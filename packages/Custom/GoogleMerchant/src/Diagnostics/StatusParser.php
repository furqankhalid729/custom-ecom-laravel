<?php

namespace Custom\GoogleMerchant\Diagnostics;

use Custom\GoogleMerchant\Enums\GoogleStatus;

/**
 * Turns a Merchant API v1 Product's productStatus into one store-side status
 * plus the item-level issues, kept in Google's own words.
 */
class StatusParser
{
    public const ISSUE_FIELDS = ['code', 'severity', 'attribute', 'description', 'detail', 'documentation', 'resolution'];

    /**
     * @return array{google_status: GoogleStatus, issues: array<int, array<string, ?string>>}
     */
    public function parse(array $product): array
    {
        $status = $product['productStatus'] ?? [];

        return [
            'google_status' => $this->status($status['destinationStatuses'] ?? []),
            'issues'        => array_map(
                fn (array $issue) => array_combine(self::ISSUE_FIELDS, array_map(fn ($f) => $issue[$f] ?? null, self::ISSUE_FIELDS)),
                array_values($status['itemLevelIssues'] ?? [])
            ),
        ];
    }

    /**
     * Disapproved: nothing approved or pending anywhere. Partial: disapproved in some
     * destinations/countries only. Pending: not shown anywhere yet. Otherwise approved.
     */
    protected function status(array $destinations): GoogleStatus
    {
        $approved = $pending = $disapproved = 0;

        foreach ($destinations as $destination) {
            $approved += count($destination['approvedCountries'] ?? []);
            $pending += count($destination['pendingCountries'] ?? []);
            $disapproved += count($destination['disapprovedCountries'] ?? []);
        }

        if ($disapproved) {
            return $approved || $pending ? GoogleStatus::Partial : GoogleStatus::Disapproved;
        }

        return $approved ? GoogleStatus::Approved : GoogleStatus::Pending;
    }
}
