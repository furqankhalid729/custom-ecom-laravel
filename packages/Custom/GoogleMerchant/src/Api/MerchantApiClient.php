<?php

namespace Custom\GoogleMerchant\Api;

use Custom\GoogleMerchant\Api\Exceptions\AuthException;
use Custom\GoogleMerchant\Api\Exceptions\UnregisteredProjectException;
use Custom\GoogleMerchant\Auth\AccessTokenProvider;
use Custom\GoogleMerchant\Models\GmcAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Thin typed wrapper over the Merchant API v1 REST endpoints used by this package.
 * See packages/Custom/GoogleMerchant/API-NOTES.md for the verified reference.
 */
class MerchantApiClient
{
    public function __construct(
        protected ErrorClassifier $classifier,
        protected ApiThrottle $throttle,
    ) {}

    public function listAccounts(GmcAccount $account): array
    {
        return $this->paginate($account, 'accounts/v1/accounts', 'accounts', 250);
    }

    public function registerGcp(GmcAccount $account, ?string $developerEmail): array
    {
        return $this->send($account, 'POST', "accounts/v1/accounts/{$account->merchant_id}/developerRegistration:registerGcp", [], array_filter(['developerEmail' => $developerEmail]));
    }

    public function listDataSources(GmcAccount $account): array
    {
        return $this->paginate($account, "datasources/v1/accounts/{$account->merchant_id}/dataSources", 'dataSources', 200);
    }

    public function createDataSource(GmcAccount $account, array $body): array
    {
        return $this->send($account, 'POST', "datasources/v1/accounts/{$account->merchant_id}/dataSources", [], $body);
    }

    public function insertProductInput(GmcAccount $account, string $dataSource, array $productInput): array
    {
        return $this->send($account, 'POST', "products/v1/accounts/{$account->merchant_id}/productInputs:insert", ['dataSource' => $dataSource], $productInput);
    }

    public function patchProductInput(GmcAccount $account, string $name, string $dataSource, array $productInput, array $updateMask): array
    {
        return $this->send($account, 'PATCH', "products/v1/{$name}", ['dataSource' => $dataSource, 'updateMask' => implode(',', $updateMask)], $productInput);
    }

    public function deleteProductInput(GmcAccount $account, string $name, string $dataSource): void
    {
        $this->send($account, 'DELETE', "products/v1/{$name}", ['dataSource' => $dataSource]);
    }

    /**
     * @return array{products: array, nextPageToken: ?string}
     */
    public function listProducts(GmcAccount $account, ?string $pageToken = null, int $pageSize = 250): array
    {
        $response = $this->send($account, 'GET', "products/v1/accounts/{$account->merchant_id}/products", array_filter(['pageSize' => $pageSize, 'pageToken' => $pageToken]));

        return ['products' => $response['products'] ?? [], 'nextPageToken' => $response['nextPageToken'] ?? null];
    }

    /** Account-level issues (suspensions, missing policies, website problems), human-readable in $languageCode. */
    public function listAccountIssues(GmcAccount $account, string $languageCode = 'en-US'): array
    {
        return $this->paginate($account, "accounts/v1/accounts/{$account->merchant_id}/issues", 'accountIssues', 100, ['languageCode' => $languageCode]);
    }

    /** The account's shipping settings, or null when none exist yet. */
    public function getShippingSettings(GmcAccount $account): ?array
    {
        try {
            return $this->send($account, 'GET', "accounts/v1/accounts/{$account->merchant_id}/shippingSettings");
        } catch (Exceptions\NotFoundException) {
            return null;
        }
    }

    /** Replaces ALL shipping settings of the account; `etag` from get ('' the first time). */
    public function insertShippingSettings(GmcAccount $account, array $body): array
    {
        return $this->send($account, 'POST', "accounts/v1/accounts/{$account->merchant_id}/shippingSettings:insert", [], $body);
    }

    public function listOnlineReturnPolicies(GmcAccount $account): array
    {
        return $this->paginate($account, "accounts/v1/accounts/{$account->merchant_id}/onlineReturnPolicies", 'onlineReturnPolicies', 100);
    }

    public function createOnlineReturnPolicy(GmcAccount $account, array $body): array
    {
        return $this->send($account, 'POST', "accounts/v1/accounts/{$account->merchant_id}/onlineReturnPolicies", [], $body);
    }

    /** v1 has no update: countries/label are immutable, so a changed policy is deleted and recreated. */
    public function deleteOnlineReturnPolicy(GmcAccount $account, string $name): void
    {
        $this->send($account, 'DELETE', "accounts/v1/{$name}");
    }

    /** Quota groups sorted by usage, highest first. */
    public function listQuotas(GmcAccount $account): array
    {
        return $this->paginate($account, "quota/v1/accounts/{$account->merchant_id}/quotas", 'quotaGroups', 1000);
    }

    protected function paginate(GmcAccount $account, string $path, string $key, int $pageSize, array $query = []): array
    {
        $items = [];
        $token = null;

        do {
            $response = $this->send($account, 'GET', $path, array_filter(['pageSize' => $pageSize, 'pageToken' => $token] + $query));

            array_push($items, ...($response[$key] ?? []));

            $token = $response['nextPageToken'] ?? null;
        } while ($token);

        return $items;
    }

    protected function send(GmcAccount $account, string $method, string $path, array $query = [], ?array $body = null, bool $retried = false): array
    {
        $this->throttle->hit($account);

        $tokens = app(AccessTokenProvider::class);

        $token = $retried ? $tokens->forceRefresh($account) : $tokens->accessToken($account);

        $url = rtrim(config('google_merchant.api_base'), '/').'/'.$path;

        if ($query) {
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        try {
            $request = Http::withToken($token)
                ->acceptJson()
                ->timeout(config('google_merchant.http_timeout', 20))
                ->connectTimeout(10);

            $response = $body === null
                ? $request->send($method, $url)
                : $request->asJson()->send($method, $url, ['json' => $body]);
        } catch (ConnectionException $e) {
            throw $this->classifier->classify($e);
        }

        if ($response->successful()) {
            return $response->json() ?? [];
        }

        $error = $this->classifier->classify($response);

        if ($error instanceof AuthException && ! $error instanceof UnregisteredProjectException && $response->status() === 401 && ! $retried) {
            return $this->send($account, $method, $path, $query, $body, true);
        }

        throw $error;
    }
}
