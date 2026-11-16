<?php

namespace Custom\GoogleMerchant\Diagnostics;

use Custom\GoogleMerchant\Api\Exceptions\GmcApiException;
use Custom\GoogleMerchant\Api\MerchantApiClient;
use Custom\GoogleMerchant\Models\GmcAccount;
use Illuminate\Support\Facades\Cache;

/**
 * Account-level issues from Google, cached for 6 hours. null = Google could not be
 * reached (not cached, so the next page load tries again).
 */
class AccountIssues
{
    public const TTL = 6 * 3600;

    /**
     * @return array<int, array{title: string, severity: string, detail: ?string, documentation: ?string, countries: array<string>}>|null
     */
    public static function get(GmcAccount $account): ?array
    {
        $key = self::key($account);

        if (Cache::has($key)) {
            return Cache::get($key);
        }

        try {
            $issues = app(MerchantApiClient::class)->listAccountIssues($account, str_replace('_', '-', app()->getLocale()));
        } catch (GmcApiException) {
            return null;
        }

        $issues = array_map(fn (array $issue) => [
            'title'         => (string) ($issue['title'] ?? ''),
            'severity'      => (string) ($issue['severity'] ?? 'SEVERITY_UNSPECIFIED'),
            'detail'        => $issue['detail'] ?? null,
            'documentation' => $issue['documentationUri'] ?? null,
            'countries'     => self::countries($issue),
        ], $issues);

        Cache::put($key, $issues, self::TTL);

        return $issues;
    }

    /** Countries the issue affects, from impactedDestinations[].impacts[].regionCode. */
    protected static function countries(array $issue): array
    {
        $countries = [];

        foreach ($issue['impactedDestinations'] ?? [] as $destination) {
            foreach ($destination['impacts'] ?? [] as $impact) {
                $countries[] = $impact['regionCode'] ?? null;
            }
        }

        return array_values(array_unique(array_filter($countries)));
    }

    public static function forget(GmcAccount $account): void
    {
        Cache::forget(self::key($account));
    }

    protected static function key(GmcAccount $account): string
    {
        return "gmc:account-issues:{$account->id}";
    }
}
