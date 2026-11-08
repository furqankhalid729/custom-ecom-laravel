<?php

use Custom\GoogleCore\OAuth\ServiceAccountTokenFetcher;
use Illuminate\Support\Facades\Http;

it('signs a service account JWT', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privatePem);
    $publicPem = openssl_pkey_get_details($key)['key'];

    Http::preventStrayRequests();
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'sa-token', 'expires_in' => 3600, 'token_type' => 'Bearer'])]);

    $token = app(ServiceAccountTokenFetcher::class)->fetch([
        'type'         => 'service_account',
        'client_email' => 'sync@project.iam.gserviceaccount.com',
        'private_key'  => $privatePem,
    ], ['https://www.googleapis.com/auth/content']);

    expect($token->accessToken)->toBe('sa-token')->and($token->refreshToken)->toBeNull();

    Http::assertSent(function ($request) use ($publicPem) {
        if ($request['grant_type'] !== 'urn:ietf:params:oauth:grant-type:jwt-bearer') {
            return false;
        }

        $parts = explode('.', $request['assertion']);

        if (count($parts) !== 3) {
            return false;
        }

        $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $signature = base64_decode(strtr($parts[2], '-_', '+/'));

        return $claims['iss'] === 'sync@project.iam.gserviceaccount.com'
            && $claims['aud'] === 'https://oauth2.googleapis.com/token'
            && $claims['scope'] === 'https://www.googleapis.com/auth/content'
            && $claims['exp'] - $claims['iat'] === 3600
            && openssl_verify($parts[0].'.'.$parts[1], $signature, $publicPem, OPENSSL_ALGO_SHA256) === 1;
    });
});
