<?php

namespace Custom\GoogleMerchant\Auth;

use Custom\GoogleCore\OAuth\GoogleOAuthClient;
use Custom\GoogleCore\OAuth\InvalidGrantException;
use Custom\GoogleCore\OAuth\OAuthException;
use Custom\GoogleCore\OAuth\ServiceAccountTokenFetcher;
use Custom\GoogleCore\OAuth\TokenResponse;
use Custom\GoogleMerchant\Api\Exceptions\AuthException;
use Custom\GoogleMerchant\Api\Exceptions\TransientApiException;
use Custom\GoogleMerchant\Enums\AccountStatus;
use Custom\GoogleMerchant\Models\GmcAccount;
use Illuminate\Support\Facades\Cache;

/**
 * The only reader of stored credentials. Refreshes under a lock so parallel
 * workers don't stampede Google's token endpoint.
 */
class TokenManager implements AccessTokenProvider
{
    public const CONTENT_SCOPE = 'https://www.googleapis.com/auth/content';

    protected const REFRESH_MARGIN_SECONDS = 300;

    public function __construct(
        protected GoogleOAuthClient $oauth,
        protected ServiceAccountTokenFetcher $serviceAccounts,
    ) {}

    public function accessToken(GmcAccount $account): string
    {
        if ($this->isFresh($account)) {
            return $account->access_token;
        }

        return Cache::lock("gmc:token:{$account->id}", 30)->block(10, function () use ($account) {
            $account->refresh();

            return $this->isFresh($account) ? $account->access_token : $this->refreshNow($account);
        });
    }

    public function forceRefresh(GmcAccount $account): string
    {
        return Cache::lock("gmc:token:{$account->id}", 30)->block(10, fn () => $this->refreshNow($account->refresh()));
    }

    protected function isFresh(GmcAccount $account): bool
    {
        return $account->access_token
            && $account->token_expires_at
            && $account->token_expires_at->getTimestamp() - time() > self::REFRESH_MARGIN_SECONDS;
    }

    protected function refreshNow(GmcAccount $account): string
    {
        try {
            $token = $this->fetch($account);
        } catch (InvalidGrantException $e) {
            $this->markNeedsReauth($account, $e->getMessage());

            throw new AuthException('Google access was revoked or expired. Reconnect the account: '.$e->getMessage(), 401, null, $e);
        } catch (OAuthException $e) {
            throw new TransientApiException($e->getMessage(), null, null, 60, $e);
        }

        $account->forceFill([
            'access_token'     => $token->accessToken,
            'token_expires_at' => $token->expiresAt,
            'refresh_token'    => $token->refreshToken ?? $account->refresh_token,
        ])->save();

        return $token->accessToken;
    }

    protected function fetch(GmcAccount $account): TokenResponse
    {
        if ($account->auth_type === 'service_account') {
            $key = json_decode((string) $account->service_account_json, true);

            if (! is_array($key)) {
                throw new InvalidGrantException('The stored service-account key is missing or unreadable.');
            }

            return $this->serviceAccounts->fetch($key, [self::CONTENT_SCOPE]);
        }

        if (! $account->refresh_token) {
            throw new InvalidGrantException('No refresh token is stored for this account.');
        }

        return $this->oauth->refresh(
            (string) config('google_merchant.client_id'),
            (string) config('google_merchant.client_secret'),
            $account->refresh_token,
        );
    }

    protected function markNeedsReauth(GmcAccount $account, string $reason): void
    {
        $account->forceFill([
            'status'     => AccountStatus::NeedsReauth->value,
            'last_error' => $reason,
        ])->save();
    }
}
