<?php

namespace Custom\GoogleCore\OAuth;

use Carbon\CarbonImmutable;

final class TokenResponse
{
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly CarbonImmutable $expiresAt,
        public readonly ?string $idToken = null,
        public readonly string $scope = '',
    ) {}

    public static function fromArray(array $data): self
    {
        if (empty($data['access_token'])) {
            throw new OAuthException('Google returned no access token.');
        }

        return new self(
            $data['access_token'],
            $data['refresh_token'] ?? null,
            CarbonImmutable::now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
            $data['id_token'] ?? null,
            (string) ($data['scope'] ?? ''),
        );
    }
}
