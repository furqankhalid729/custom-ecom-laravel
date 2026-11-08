<?php

namespace Custom\GoogleCore\OAuth;

class ServiceAccountTokenFetcher extends GoogleOAuthClient
{
    /**
     * JWT-bearer grant (RFC 7523) for a Google service-account key.
     */
    public function fetch(array $keyJson, array $scopes): TokenResponse
    {
        if (($keyJson['type'] ?? null) !== 'service_account' || empty($keyJson['client_email']) || empty($keyJson['private_key'])) {
            throw new OAuthException('The key file is not a Google service-account JSON key.');
        }

        $now = time();

        $segments = [
            $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64Url(json_encode([
                'iss'   => $keyJson['client_email'],
                'scope' => implode(' ', $scopes),
                'aud'   => self::TOKEN_URL,
                'iat'   => $now,
                'exp'   => $now + 3600,
            ], JSON_UNESCAPED_SLASHES)),
        ];

        $key = openssl_pkey_get_private($keyJson['private_key']);

        if ($key === false || ! openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new OAuthException('The service-account private key could not be used to sign.');
        }

        $segments[] = $this->base64Url($signature);

        return $this->tokenRequest([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => implode('.', $segments),
        ]);
    }

    protected function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
