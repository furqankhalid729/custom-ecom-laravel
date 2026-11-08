<?php

require_once __DIR__.'/../Helpers.php';

use Custom\GoogleMerchant\Api\Exceptions\AuthException;
use Custom\GoogleMerchant\Api\Exceptions\NotFoundException;
use Custom\GoogleMerchant\Api\Exceptions\PermanentApiException;
use Custom\GoogleMerchant\Api\Exceptions\QuotaExhaustedException;
use Custom\GoogleMerchant\Api\Exceptions\TransientApiException;
use Custom\GoogleMerchant\Api\Exceptions\UnregisteredProjectException;
use Custom\GoogleMerchant\Api\MerchantApiClient;
use Custom\GoogleMerchant\Api\ResponseRedactor;
use Custom\GoogleMerchant\Auth\AccessTokenProvider;
use Custom\GoogleMerchant\Models\GmcAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->tokens = new class implements AccessTokenProvider
    {
        public int $refreshes = 0;

        public function accessToken(GmcAccount $account): string
        {
            return $this->refreshes ? 'refreshed-token' : 'test-token';
        }

        public function forceRefresh(GmcAccount $account): string
        {
            $this->refreshes++;

            return 'refreshed-token';
        }
    };

    app()->instance(AccessTokenProvider::class, $this->tokens);

    $this->account = gmcAccount();
    RateLimiter::clear('gmc-api:'.$this->account->id);
});

it('inserts a product input with bearer token and dataSource query', function () {
    Http::fake(['merchantapi.googleapis.com/*' => Http::response(fixture('productinput-insert'))]);

    $result = app(MerchantApiClient::class)->insertProductInput($this->account, 'accounts/123456/dataSources/999', [
        'offerId' => 'sku-1', 'contentLanguage' => 'en', 'feedLabel' => 'US', 'productAttributes' => ['title' => 'Shirt'],
    ]);

    expect($result['offerId'])->toBe('sku-1');

    Http::assertSent(fn ($r) => $r->method() === 'POST'
        && $r->url() === 'https://merchantapi.googleapis.com/products/v1/accounts/123456/productInputs:insert?dataSource=accounts%2F123456%2FdataSources%2F999'
        && $r->hasHeader('Authorization', 'Bearer test-token')
        && $r['offerId'] === 'sku-1');
});

it('deletes a product input by name with the data source', function () {
    Http::fake(['merchantapi.googleapis.com/*' => Http::response('', 200)]);

    app(MerchantApiClient::class)->deleteProductInput($this->account, 'accounts/123456/productInputs/ZW5-VVN-c2t1LTE', 'accounts/123456/dataSources/999');

    Http::assertSent(fn ($r) => $r->method() === 'DELETE'
        && $r->url() === 'https://merchantapi.googleapis.com/products/v1/accounts/123456/productInputs/ZW5-VVN-c2t1LTE?dataSource=accounts%2F123456%2FdataSources%2F999');
});

it('classifies errors', function (int $status, ?string $fixtureName, array $headers, string $expected) {
    Http::fake(['merchantapi.googleapis.com/*' => Http::response($fixtureName ? fixture($fixtureName) : '', $status, $headers)]);

    try {
        app(MerchantApiClient::class)->listDataSources($this->account);
        $this->fail('expected exception');
    } catch (\Throwable $e) {
        expect($e)->toBeInstanceOf($expected)
            ->and($e->httpStatus)->toBe($status);

        if ($e instanceof TransientApiException && isset($headers['Retry-After'])) {
            expect($e->retryAfter)->toBe((int) $headers['Retry-After']);
        }

        if ($e instanceof PermanentApiException) {
            expect($e->fieldViolations)->toBe(['productAttributes.gtins' => 'Invalid GTIN value.']);
        }
    }
})->with([
    'rate limited'  => [429, 'error-429', ['Retry-After' => '120'], TransientApiException::class],
    'daily quota'   => [429, 'error-429-quota', [], QuotaExhaustedException::class],
    'unavailable'   => [503, 'error-503', [], TransientApiException::class],
    'bad request'   => [400, 'error-400-invalid-argument', [], PermanentApiException::class],
    'not found'     => [404, 'error-404', [], NotFoundException::class],
    'unregistered'  => [401, 'error-401-unregistered', [], UnregisteredProjectException::class],
    'forbidden'     => [403, null, [], AuthException::class],
]);

it('uses 60 seconds when a transient error has no Retry-After', function () {
    Http::fake(['merchantapi.googleapis.com/*' => Http::response(fixture('error-503'), 503)]);

    try {
        app(MerchantApiClient::class)->listDataSources($this->account);
    } catch (TransientApiException $e) {
        expect($e->retryAfter)->toBe(60);
    }
});

it('treats connection failures as transient', function () {
    Http::fake(['merchantapi.googleapis.com/*' => Http::failedConnection('timed out')]);

    app(MerchantApiClient::class)->listDataSources($this->account);
})->throws(TransientApiException::class);

it('refreshes once on 401 then succeeds', function () {
    Http::fake(['merchantapi.googleapis.com/*' => Http::sequence()
        ->push(fixture('error-401'), 401)
        ->push(fixture('datasources-list'), 200)]);

    $sources = app(MerchantApiClient::class)->listDataSources($this->account);

    expect($sources)->toHaveCount(1)
        ->and($this->tokens->refreshes)->toBe(1);

    Http::assertSentCount(2);
    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer refreshed-token'));
});

it('gives up after a second 401', function () {
    Http::fake(['merchantapi.googleapis.com/*' => Http::response(fixture('error-401'), 401)]);

    try {
        app(MerchantApiClient::class)->listDataSources($this->account);
        $this->fail('expected exception');
    } catch (AuthException $e) {
        expect($this->tokens->refreshes)->toBe(1);
        Http::assertSentCount(2);
    }
});

it('redacts secrets in excerpts', function () {
    $excerpt = app(ResponseRedactor::class)->redact('{"access_token":"ya29.x","refresh_token":"1//r","private_key":"-----BEGIN","contact":"a@b.com","Authorization":"Bearer abc"}');

    expect($excerpt)->not->toContain('ya29.x')
        ->and($excerpt)->not->toContain('1//r')
        ->and($excerpt)->not->toContain('BEGIN')
        ->and($excerpt)->not->toContain('a@b.com')
        ->and($excerpt)->not->toContain('Bearer abc')
        ->and(strlen(app(ResponseRedactor::class)->redact(str_repeat('x', 5000))))->toBeLessThanOrEqual(2048);
});

it('throttles per account', function () {
    gmcConfig(['settings.rate_limit_per_minute' => 2]);

    Http::fake(['merchantapi.googleapis.com/*' => Http::response(fixture('datasources-list'))]);

    $client = app(MerchantApiClient::class);

    $client->listDataSources($this->account);
    $client->listDataSources($this->account);

    try {
        $client->listDataSources($this->account);
        $this->fail('expected throttle');
    } catch (TransientApiException $e) {
        expect($e->retryAfter)->toBeGreaterThan(0);
        Http::assertSentCount(2);
    }
});

it('lists accessible accounts and creates a data source', function () {
    Http::fake([
        'merchantapi.googleapis.com/accounts/v1/accounts*'          => Http::response(fixture('accounts-list')),
        'merchantapi.googleapis.com/datasources/v1/*/dataSources'   => Http::response(fixture('datasource-create')),
    ]);

    $client = app(MerchantApiClient::class);

    expect($client->listAccounts($this->account)[0]['accountId'])->toBe('123456')
        ->and($client->createDataSource($this->account, ['displayName' => 'x'])['name'])->toBe('accounts/123456/dataSources/999');
});
