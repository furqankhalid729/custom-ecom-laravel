<?php

namespace Custom\GoogleMerchant\Diagnostics;

use Custom\GoogleMerchant\Api\Exceptions\GmcApiException;
use Custom\GoogleMerchant\Api\MerchantApiClient;
use Custom\GoogleMerchant\Models\GmcAccount;
use Illuminate\Support\Facades\Cache;

/**
 * Merchant API daily quota usage, cached for 1 hour. null = Google could not be reached.
 */
class Quotas
{
    public const TTL = 3600;

    /**
     * @return array<int, array{name: string, usage: int, limit: int, minute_limit: int, percent: int}>|null
     */
    public static function get(GmcAccount $account): ?array
    {
        $key = self::key($account);

        if (Cache::has($key)) {
            return Cache::get($key);
        }

        try {
            $groups = app(MerchantApiClient::class)->listQuotas($account);
        } catch (GmcApiException) {
            return null;
        }

        $quotas = array_map(function (array $group) {
            $usage = (int) ($group['quotaUsage'] ?? 0);
            $limit = (int) ($group['quotaLimit'] ?? 0);

            return [
                'name' => basename((string) ($group['name'] ?? '')),
                'usage' => $usage,
                'limit' => $limit,
                'minute_limit' => (int) ($group['quotaMinuteLimit'] ?? 0),
                'percent' => $limit > 0 ? (int) min(100, round($usage / $limit * 100)) : 0,
            ];
        }, $groups);

        Cache::put($key, $quotas, self::TTL);

        return $quotas;
    }

    public static function forget(GmcAccount $account): void
    {
        Cache::forget(self::key($account));
    }

    protected static function key(GmcAccount $account): string
    {
        return "gmc:quotas:{$account->id}";
    }
}
