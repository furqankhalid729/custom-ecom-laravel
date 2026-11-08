<?php

namespace Custom\GoogleCore\OAuth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GoogleOAuthClient
{
    public const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public function authUrl(string $clientId, string $redirectUri, array $scopes, string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id'              => $clientId,
            'redirect_uri'           => $redirectUri,
            'response_type'          => 'code',
            'scope'                  => implode(' ', $scopes),
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
            'state'                  => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $clientId, string $secret, string $redirectUri, string $code): TokenResponse
    {
        return $this->tokenRequest([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_id'     => $clientId,
            'client_secret' => $secret,
            'redirect_uri'  => $redirectUri,
        ]);
    }

    public function refresh(string $clientId, string $secret, string $refreshToken): TokenResponse
    {
        return $this->tokenRequest([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id'     => $clientId,
            'client_secret' => $secret,
        ]);
    }

    public function revoke(string $token): void
    {
        try {
            Http::asForm()->timeout(10)->post(self::REVOKE_URL, ['token' => $token]);
        } catch (ConnectionException) {
            // Best effort: local tokens are wiped either way.
        }
    }

    /**
     * Reads the email claim. The id_token came straight from Google's token
     * endpoint over TLS, so the signature is not re-verified here.
     */
    public function emailFromIdToken(?string $idToken): ?string
    {
        $parts = explode('.', (string) $idToken);

        if (count($parts) !== 3) {
            return null;
        }

        $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        return is_array($claims) ? ($claims['email'] ?? null) : null;
    }

    protected function tokenRequest(array $form): TokenResponse
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(15)->post(self::TOKEN_URL, $form);
        } catch (ConnectionException $e) {
            throw new OAuthException('Could not reach Google: '.$e->getMessage(), 0, $e);
        }

        return $this->parse($response);
    }

    protected function parse(Response $response): TokenResponse
    {
        if ($response->successful()) {
            return TokenResponse::fromArray($response->json() ?? []);
        }

        $error = $response->json('error');
        $description = $response->json('error_description') ?? (is_string($error) ? $error : 'unknown error');

        if ($error === 'invalid_grant') {
            throw new InvalidGrantException($description);
        }

        throw new OAuthException("Google token request failed ({$response->status()}): {$description}");
    }
}
