<?php

require_once __DIR__.'/../Helpers.php';

use Custom\GoogleMerchant\Api\Exceptions\AuthException;
use Custom\GoogleMerchant\Auth\AccessTokenProvider;
use Custom\GoogleMerchant\Auth\TokenManager;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['google_merchant.client_id' => 'client-1', 'google_merchant.client_secret' => 'secret-1']);
});

it('is bound as the access token provider', function () {
    expect(app(AccessTokenProvider::class))->toBeInstanceOf(TokenManager::class);
});

it('returns the stored token while it is valid for more than five minutes', function () {
    $account = gmcAccount(['access_token' => 'still-good', 'token_expires_at' => now()->addHour()]);

    expect(app(TokenManager::class)->accessToken($account))->toBe('still-good');

    Http::assertNothingSent();
});

it('refreshes an oauth token that expires within five minutes', function () {
    Http::fake(['oauth2.googleapis.com/token' => Http::response(fixture('token'))]);

    $account = gmcAccount([
        'auth_type'            => 'oauth',
        'service_account_json' => null,
        'access_token'         => 'old',
        'refresh_token'        => '1//refresh-token',
        'token_expires_at'     => now()->addMinutes(2),
    ]);

    expect(app(TokenManager::class)->accessToken($account))->toBe('ya29.fresh-token');

    $account->refresh();

    expect($account->access_token)->toBe('ya29.fresh-token')
        ->and($account->token_expires_at->greaterThan(now()->addMinutes(50)))->toBeTrue();

    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === '1//refresh-token' && $r['client_id'] === 'client-1');
});

it('marks the account needs_reauth on invalid_grant', function () {
    Http::fake(['oauth2.googleapis.com/token' => Http::response(fixture('token-invalid-grant'), 400)]);

    $account = gmcAccount([
        'auth_type'            => 'oauth',
        'service_account_json' => null,
        'refresh_token'        => 'revoked',
        'token_expires_at'     => now()->subMinute(),
    ]);

    try {
        app(TokenManager::class)->accessToken($account);
        $this->fail('expected AuthException');
    } catch (AuthException) {
        $account->refresh();

        expect($account->status)->toBe('needs_reauth')
            ->and($account->last_error)->toContain('expired or revoked');
    }
});

it('uses the jwt-bearer grant for service accounts', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);

    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'sa-token', 'expires_in' => 3600])]);

    $account = gmcAccount([
        'service_account_json' => json_encode(['type' => 'service_account', 'client_email' => 'sync@p.iam.gserviceaccount.com', 'private_key' => $pem]),
        'token_expires_at'     => null,
        'access_token'         => null,
    ]);

    expect(app(TokenManager::class)->accessToken($account))->toBe('sa-token');

    Http::assertSent(fn ($r) => $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer');
});
