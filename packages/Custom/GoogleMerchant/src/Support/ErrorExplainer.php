<?php

namespace Custom\GoogleMerchant\Support;

use Custom\GoogleMerchant\Models\GmcProduct;
use Custom\GoogleMerchant\Models\GmcSyncLog;

/**
 * Turns Google errors and store validation results into a title + fix hint an
 * admin can act on. Unknown errors fall back to Google's own message.
 */
class ErrorExplainer
{
    protected array $table;

    public function __construct()
    {
        $this->table = require __DIR__.'/error-hints.php';
    }

    /**
     * @return array{title: string, hint: string}
     */
    public function explain(GmcSyncLog|GmcProduct $entry): array
    {
        if ($entry instanceof GmcProduct && $entry->state === 'invalid' && $entry->validation_errors) {
            return [
                'title' => trans('google_merchant::app.hints.invalid.title'),
                'hint'  => implode(' ', $entry->validation_errors),
            ];
        }

        $message = $entry instanceof GmcProduct ? (string) $entry->last_error : (string) $entry->error_message;
        $status = $entry instanceof GmcSyncLog ? $entry->http_status : null;

        if ($field = $this->field($entry, $message)) {
            if ($key = $this->table['fields'][$field] ?? null) {
                return [
                    'title' => trans("google_merchant::app.hints.fields.$key.title"),
                    'hint'  => trans("google_merchant::app.hints.fields.$key.hint"),
                ];
            }
        }

        if ($status && ($code = $this->httpKey($status))) {
            return [
                'title' => trans("google_merchant::app.hints.http.$code.title"),
                'hint'  => trans("google_merchant::app.hints.http.$code.hint"),
            ];
        }

        return [
            'title' => trans('google_merchant::app.hints.unknown.title'),
            'hint'  => $message !== '' ? $message : trans('google_merchant::app.hints.unknown.hint'),
        ];
    }

    /** The Google field the error is about: from fieldViolations, or a "[field] …" message prefix. */
    protected function field(GmcSyncLog|GmcProduct $entry, string $message): ?string
    {
        if ($entry instanceof GmcSyncLog && $entry->response_excerpt) {
            $decoded = json_decode($entry->response_excerpt, true);

            foreach ((array) ($decoded['error']['details'] ?? []) as $detail) {
                foreach ((array) ($detail['fieldViolations'] ?? []) as $violation) {
                    if (! empty($violation['field'])) {
                        return $this->normalise($violation['field']);
                    }
                }
            }
        }

        if (preg_match('/\[([A-Za-z0-9_.]+)\]/', $message, $m) || preg_match('/\((productAttributes\.[A-Za-z0-9_]+):/', $message, $m)) {
            return $this->normalise($m[1]);
        }

        return null;
    }

    protected function normalise(string $field): string
    {
        $field = preg_replace('/\[\d+\]/', '', $field);

        return $this->table['aliases'][$field] ?? $field;
    }

    protected function httpKey(int $status): ?int
    {
        if ($status >= 500) {
            return 500;
        }

        return in_array($status, $this->table['http'], true) ? $status : null;
    }
}
