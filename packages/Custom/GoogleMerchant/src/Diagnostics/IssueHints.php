<?php

namespace Custom\GoogleMerchant\Diagnostics;

/**
 * Fix suggestion for a Google item-level issue, naming the store field to change.
 * Unknown attributes fall back to Google's own detail text.
 */
class IssueHints
{
    public static function for(string $code, ?string $attribute, ?string $detail = null, ?string $resolution = null): string
    {
        $table = require __DIR__.'/../Support/error-hints.php';

        $key = $table['issue_attributes'][$attribute ?? ''] ?? $table['issue_codes'][$code] ?? null;

        if ($key) {
            return trans("google_merchant::app.hints.fields.$key.hint");
        }

        // `resolution` is a machine value (e.g. merchant_action) in v1, so prefer the detail text.
        return (string) ($detail ?: (str_contains((string) $resolution, ' ') ? $resolution : ''));
    }
}
