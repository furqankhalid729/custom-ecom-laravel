<?php

use Custom\GoogleCore\OAuth\GoogleOAuthClient;
use Custom\GoogleCore\OAuth\InvalidGrantException;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Http::preventStrayRequests());

it('builds an offline consent auth url', function () {
    $url = app(GoogleOAuthClient::class)->authUrl(
        'client-1',
        'https://shop.example.com/admin/google-merchant/oauth/callback',
        ['https://www.googleapis.com/auth/content', 'openid', 'email'],
        'state-123'
    );

    expect($url)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?');

    parse_str(parse_url($url, PHP_URL_QUERY), $query);

    expect($query['access_type'])->toBe('offline')
        ->and($query['prompt'])->toBe('consent')
        ->and($query['response_type'])->toBe('code')
        ->and($query['client_id'])->toBe('client-1')
        ->and($query['scope'])->toContain('https://www.googleapis.com/auth/content')
        ->and($query['scope'])->toContain('openid email')
        ->and($query['state'])->toBe('state-123');
});

it('exchanges a code', function () {
    Http::fake(['oauth2.googleapis.com/token' => Http::response(json_decode(file_get_contents(__DIR__.'/../../../GoogleMerchant/tests/Fixtures/token.json'), true))]);

    $token = app(GoogleOAuthClient::class)->exchangeCode('c', 's', 'https://x.example.com/cb', 'code-1');

    expect($token->accessToken)->toBe('ya29.fresh-token')
        ->and($token->refreshToken)->toBe('1//refresh-token')
        ->and(abs($token->expiresAt->getTimestamp() - (time() + 3599)))->toBeLessThan(5);

    Http::assertSent(fn ($r) => $r['grant_type'] === 'authorization_code' && $r['code'] === 'code-1');
});

it('throws InvalidGrantException on invalid_grant', function () {
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)]);

    app(GoogleOAuthClient::class)->refresh('c', 's', 'old-refresh');
})->throws(InvalidGrantException::class);

it('reads the email from an id token', function () {
    $payload = rtrim(strtr(base64_encode(json_encode(['email' => 'owner@example.com'])), '+/', '-_'), '=');

    expect(app(GoogleOAuthClient::class)->emailFromIdToken("h.$payload.s"))->toBe('owner@example.com');
});
