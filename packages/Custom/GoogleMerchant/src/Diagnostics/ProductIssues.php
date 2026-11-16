<?php

namespace Custom\GoogleMerchant\Diagnostics;

use Custom\GoogleMerchant\Enums\GoogleStatus;
use Custom\GoogleMerchant\Enums\SyncState;
use Custom\GoogleMerchant\Models\GmcProductProxy;
use Custom\GoogleMerchant\Models\GmcTarget;

/**
 * Product problems of one target, grouped for the Diagnostics page: Google's item-level
 * issues (by issue code) and the store's own validation errors (by field). Every issue
 * lands in exactly one group.
 */
class ProductIssues
{
    public const GROUPS = ['missing', 'price', 'image', 'identifier', 'policy', 'other'];

    /**
     * @return array<string, array<int, array{code: string, count: int, description: string, hint: string, documentation: ?string, link: string}>>
     */
    public function groups(GmcTarget $target): array
    {
        $buckets = array_fill_keys(self::GROUPS, []);
        $hints = require __DIR__.'/../Support/error-hints.php';

        $rows = $this->rows($target)
            ->where(fn ($q) => $q->whereNotNull('google_issues')->orWhereNotNull('validation_errors'))
            ->select(['id', 'state', 'google_issues', 'validation_errors'])
            ->cursor();

        foreach ($rows as $row) {
            foreach (collect($row->google_issues ?? [])->unique('code') as $issue) {
                $code = (string) ($issue['code'] ?? '');

                if ($code === '') {
                    continue;
                }

                $group = $this->classify($code, (string) ($issue['attribute'] ?? ''));

                $buckets[$group][$code] ??= [
                    'code'          => $code,
                    'count'         => 0,
                    'description'   => (string) ($issue['description'] ?? $code),
                    'hint'          => IssueHints::for($code, $issue['attribute'] ?? null, $issue['detail'] ?? null, $issue['resolution'] ?? null),
                    'documentation' => $issue['documentation'] ?? null,
                    'link'          => route('admin.google_merchant.products.index', ['issue' => $code]),
                ];

                $buckets[$group][$code]['count']++;
            }

            if ($row->state !== SyncState::Invalid->value) {
                continue;
            }

            foreach ($row->validation_errors ?? [] as $field => $message) {
                $code = "store:$field";
                $field = $hints['aliases'][$field] ?? ($field === 'offerId' || str_contains($field, '.') ? $field : "productAttributes.$field");
                $hintKey = $hints['fields'][$field] ?? null;

                $buckets['missing'][$code] ??= [
                    'code'          => $code,
                    'count'         => 0,
                    'description'   => (string) $message,
                    'hint'          => $hintKey ? trans("google_merchant::app.hints.fields.$hintKey.hint") : '',
                    'documentation' => null,
                    'link'          => route('admin.google_merchant.products.index', ['state' => SyncState::Invalid->value]),
                ];

                $buckets['missing'][$code]['count']++;
            }
        }

        return array_map(
            fn (array $issues) => collect($issues)->sortByDesc('count')->values()->all(),
            $buckets
        );
    }

    /**
     * Products per Google status. "awaiting" = sent to Google, no review result yet;
     * those are never counted as approved.
     *
     * @return array{approved: int, disapproved: int, partial: int, pending: int, awaiting: int}
     */
    public function summary(GmcTarget $target): array
    {
        $counts = $this->rows($target)
            ->whereNotNull('google_status')
            ->selectRaw('google_status, count(*) as aggregate')
            ->groupBy('google_status')
            ->pluck('aggregate', 'google_status');

        $summary = [];

        foreach (GoogleStatus::cases() as $status) {
            $summary[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        $summary['awaiting'] = $this->rows($target)
            ->where('state', SyncState::Synced->value)
            ->whereNull('google_status')
            ->count();

        return $summary;
    }

    protected function classify(string $code, string $attribute): string
    {
        $code = strtolower($code);

        return match (true) {
            str_starts_with($code, 'missing_')                                                         => 'missing',
            (bool) preg_match('/policy|prohibited|restricted|counterfeit|adult|dangerous/', $code)     => 'policy',
            in_array($attribute, ['price', 'sale_price'], true) || str_contains($code, 'price')         => 'price',
            in_array($attribute, ['image_link', 'additional_image_link'], true) || str_contains($code, 'image') => 'image',
            in_array($attribute, ['gtin', 'mpn', 'brand', 'identifier_exists'], true)
                || (bool) preg_match('/gtin|upc|ean|isbn|mpn|identifier|brand/', $code)                 => 'identifier',
            default                                                                                     => 'other',
        };
    }

    protected function rows(GmcTarget $target)
    {
        return GmcProductProxy::modelClass()::query()
            ->where('gmc_target_id', $target->id)
            ->whereNotIn('state', [SyncState::Deleted->value, SyncState::Excluded->value]);
    }
}
