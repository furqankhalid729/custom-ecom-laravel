<?php

namespace Custom\GoogleMerchant\Services;

use Custom\GoogleCore\Audit\AuditLogger;
use Custom\GoogleCore\OAuth\GoogleOAuthClient;
use Custom\GoogleCore\OAuth\OAuthException;
use Custom\GoogleCore\OAuth\ServiceAccountTokenFetcher;
use Custom\GoogleMerchant\Api\Exceptions\GmcApiException;
use Custom\GoogleMerchant\Api\Exceptions\PermanentApiException;
use Custom\GoogleMerchant\Api\Exceptions\UnregisteredProjectException;
use Custom\GoogleMerchant\Api\MerchantApiClient;
use Custom\GoogleMerchant\Auth\TokenManager;
use Custom\GoogleMerchant\Enums\AccountStatus;
use Custom\GoogleMerchant\Models\GmcAccount;
use Custom\GoogleMerchant\Models\GmcAccountProxy;
use Illuminate\Support\Str;

class ConnectionService
{
    public const AUDIT = 'merchant_center';

    public const STATE_KEY = 'gmc_oauth_state';

    public function __construct(
        protected GoogleOAuthClient $oauth,
        protected ServiceAccountTokenFetcher $serviceAccounts,
        protected MerchantApiClient $client,
        protected DataSourceProvisioner $provisioner,
        protected AuditLogger $audit,
    ) {}

    public function current(): ?GmcAccount
    {
        return GmcAccountProxy::modelClass()::query()->orderBy('id')->first();
    }

    public function redirectUri(): string
    {
        return config('google_merchant.redirect_uri') ?: route('admin.google_merchant.oauth.callback');
    }

    public function hasOAuthCredentials(): bool
    {
        return filled(config('google_merchant.client_id')) && filled(config('google_merchant.client_secret'));
    }

    public function startOAuth(): string
    {
        session([self::STATE_KEY => $state = Str::random(40)]);

        return $this->oauth->authUrl(
            (string) config('google_merchant.client_id'),
            $this->redirectUri(),
            config('google_merchant.scopes'),
            $state,
        );
    }

    public function completeOAuth(?string $state, ?string $code): GmcAccount
    {
        $expected = session()->pull(self::STATE_KEY);

        if (! is_string($expected) || ! is_string($state) || ! hash_equals($expected, $state)) {
            throw new OAuthException('state_mismatch');
        }

        if (! $code) {
            throw new OAuthException('missing_code');
        }

        $token = $this->oauth->exchangeCode(
            (string) config('google_merchant.client_id'),
            (string) config('google_merchant.client_secret'),
            $this->redirectUri(),
            $code,
        );

        if (! $token->refreshToken) {
            throw new OAuthException('no_refresh_token');
        }

        $email = $this->oauth->emailFromIdToken($token->idToken);

        $account = $this->upsert([
            'auth_type'            => 'oauth',
            'google_email'         => $email,
            'developer_email'      => $email,
            'access_token'         => $token->accessToken,
            'refresh_token'        => $token->refreshToken,
            'token_expires_at'     => $token->expiresAt,
            'service_account_json' => null,
        ]);

        $this->audit->record(self::AUDIT, 'connect', ['auth_type' => 'oauth', 'google_email' => $email]);

        return $account;
    }

    public function connectServiceAccount(array $keyJson, ?string $developerEmail = null): GmcAccount
    {
        // Proves the key works before it is stored.
        $token = $this->serviceAccounts->fetch($keyJson, [TokenManager::CONTENT_SCOPE]);

        $account = $this->upsert([
            'auth_type'            => 'service_account',
            'google_email'         => $keyJson['client_email'],
            'developer_email'      => $developerEmail ?: $this->current()?->developer_email,
            'service_account_json' => json_encode($keyJson),
            'access_token'         => $token->accessToken,
            'refresh_token'        => null,
            'token_expires_at'     => $token->expiresAt,
        ]);

        $this->audit->record(self::AUDIT, 'connect', ['auth_type' => 'service_account', 'google_email' => $keyJson['client_email']]);

        return $account;
    }

    /**
     * Verifies access, registers this Cloud project with the account once, and
     * provisions the data source of the default target.
     *
     * @throws GmcApiException|\InvalidArgumentException
     */
    public function selectMerchant(GmcAccount $account, string $merchantId, ?string $developerEmail = null): void
    {
        if ($developerEmail) {
            $account->forceFill(['developer_email' => $developerEmail])->save();
        }

        $account->forceFill(['merchant_id' => $merchantId])->save();

        try {
            $accounts = $this->client->listAccounts($account);
        } catch (UnregisteredProjectException) {
            $this->register($account);

            $accounts = $this->client->listAccounts($account);
        }

        $match = collect($accounts)->first(fn ($a) => (string) ($a['accountId'] ?? '') === $merchantId
            || ($a['name'] ?? null) === "accounts/{$merchantId}");

        if (! $match) {
            $account->forceFill(['merchant_id' => null])->save();

            throw new \InvalidArgumentException(trans('google_merchant::app.connection.merchant-not-accessible', ['id' => $merchantId]));
        }

        $account->forceFill(['display_name' => $match['accountName'] ?? null])->save();

        if (! $account->developer_registered_at) {
            $this->register($account);
        }

        $this->provisioner->ensure($this->provisioner->ensureDefaultTarget($account));

        $this->audit->record(self::AUDIT, 'select_merchant', ['merchant_id' => $merchantId]);
    }

    public function disconnect(GmcAccount $account): void
    {
        if ($account->auth_type === 'oauth') {
            foreach (array_filter([$account->refresh_token, $account->access_token]) as $token) {
                $this->oauth->revoke($token);

                break; // revoking the refresh token also revokes its access tokens
            }
        }

        $account->forceFill([
            'access_token'         => null,
            'refresh_token'        => null,
            'service_account_json' => null,
            'token_expires_at'     => null,
            'status'               => AccountStatus::Disconnected->value,
        ])->save();

        $this->audit->record(self::AUDIT, 'disconnect', ['merchant_id' => $account->merchant_id]);
    }

    protected function register(GmcAccount $account): void
    {
        try {
            $this->client->registerGcp($account, $account->developer_email);
        } catch (PermanentApiException $e) {
            if (! str_contains(strtolower($e->getMessage()), 'already')) {
                throw $e;
            }
        }

        $account->forceFill(['developer_registered_at' => now()])->save();
    }

    protected function upsert(array $attributes): GmcAccount
    {
        $account = $this->current() ?? GmcAccountProxy::modelClass()::make();

        $account->forceFill(array_merge($attributes, [
            'status'       => AccountStatus::Connected->value,
            'last_error'   => null,
            'connected_by' => auth()->guard('admin')->id(),
        ]))->save();

        return $account;
    }
}
