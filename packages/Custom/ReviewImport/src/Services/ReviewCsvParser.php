<?php

namespace Custom\ReviewImport\Services;

use Carbon\Carbon;
use Throwable;
use Webkul\Customer\Models\Customer;
use Webkul\Product\Models\Product;

/**
 * Reads a review CSV and validates every row.
 *
 * Returns ['rows' => valid rows ready to save, 'errors' => [line => [messages]], 'total' => data rows read].
 * A file-level problem (unreadable, missing columns, too many rows) is reported as line 0.
 */
class ReviewCsvParser
{
    public const COLUMNS = ['sku', 'name', 'rating', 'title', 'comment', 'status', 'date', 'customer_email'];

    public const REQUIRED_COLUMNS = ['sku', 'name', 'rating'];

    public const STATUSES = ['approved', 'pending', 'disapproved'];

    public const MAX_ROWS = 5000;

    public function parse(string $path): array
    {
        $result = ['rows' => [], 'errors' => [], 'total' => 0];

        $handle = @fopen($path, 'r');

        if (! $handle) {
            return $this->fileError($result, trans('review_import::app.errors.unreadable'));
        }

        try {
            $firstLine = fgets($handle);

            if ($firstLine === false || trim($firstLine) === '') {
                return $this->fileError($result, trans('review_import::app.errors.empty'));
            }

            // Excel in many European locales saves CSV with semicolons.
            $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

            rewind($handle);

            $header = array_map(
                fn ($column) => strtolower(trim($this->stripBom((string) $column))),
                fgetcsv($handle, 0, $delimiter) ?: []
            );

            $missing = array_diff(self::REQUIRED_COLUMNS, $header);

            if ($missing) {
                return $this->fileError($result, trans('review_import::app.errors.missing-columns', [
                    'columns' => implode(', ', $missing),
                ]));
            }

            $raw = [];
            $line = 1;

            while (($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
                $line++;

                if ($cells === [null] || implode('', array_map('trim', array_map('strval', $cells))) === '') {
                    continue;
                }

                if (count($raw) >= self::MAX_ROWS) {
                    return $this->fileError($result, trans('review_import::app.errors.too-many-rows', ['max' => self::MAX_ROWS]));
                }

                $row = [];

                foreach ($header as $index => $column) {
                    $row[$column] = $this->clean($cells[$index] ?? '');
                }

                $raw[$line] = $row;
            }
        } finally {
            fclose($handle);
        }

        $result['total'] = count($raw);

        if (! $raw) {
            return $this->fileError($result, trans('review_import::app.errors.no-rows'));
        }

        $products = Product::query()
            ->whereIn('sku', array_unique(array_column($raw, 'sku')))
            ->pluck('id', 'sku');

        $emails = array_filter(array_unique(array_map('strtolower', array_column($raw, 'customer_email'))));

        $customers = $emails
            ? Customer::query()->whereIn('email', $emails)->pluck('id', 'email')->mapWithKeys(fn ($id, $email) => [strtolower($email) => $id])
            : collect();

        foreach ($raw as $line => $row) {
            [$review, $errors] = $this->validateRow($row, $products, $customers);

            if ($errors) {
                $result['errors'][$line] = $errors;
            } else {
                $result['rows'][$line] = $review;
            }
        }

        return $result;
    }

    protected function validateRow(array $row, $products, $customers): array
    {
        $errors = [];

        $sku = $row['sku'] ?? '';
        $name = $row['name'] ?? '';
        $rating = $row['rating'] ?? '';
        $status = strtolower($row['status'] ?? '') ?: 'approved';
        $date = $row['date'] ?? '';
        $email = strtolower($row['customer_email'] ?? '');

        if ($sku === '') {
            $errors[] = trans('review_import::app.errors.sku-required');
        } elseif (! isset($products[$sku])) {
            $errors[] = trans('review_import::app.errors.sku-not-found', ['sku' => $sku]);
        }

        if ($name === '') {
            $errors[] = trans('review_import::app.errors.name-required');
        } elseif (mb_strlen($name) > 255) {
            $errors[] = trans('review_import::app.errors.too-long', ['field' => 'name']);
        }

        if (! preg_match('/^[1-5]$/', $rating)) {
            $errors[] = trans('review_import::app.errors.rating', ['value' => $rating]);
        }

        if (mb_strlen($row['title'] ?? '') > 255) {
            $errors[] = trans('review_import::app.errors.too-long', ['field' => 'title']);
        }

        if (! in_array($status, self::STATUSES, true)) {
            $errors[] = trans('review_import::app.errors.status', [
                'value' => $status,
                'allowed' => implode(', ', self::STATUSES),
            ]);
        }

        $createdAt = null;

        if ($date !== '') {
            $createdAt = $this->parseDate($date);

            if (! $createdAt) {
                $errors[] = trans('review_import::app.errors.date', ['value' => $date]);
            } elseif ($createdAt->isFuture()) {
                $errors[] = trans('review_import::app.errors.future-date', ['value' => $date]);
            }
        }

        if ($errors) {
            return [null, $errors];
        }

        return [[
            'product_id' => $products[$sku],
            'sku' => $sku,
            'name' => $name,
            'rating' => (int) $rating,
            'title' => $row['title'] ?? '',
            'comment' => ($row['comment'] ?? '') !== '' ? $row['comment'] : null,
            'status' => $status,
            'customer_id' => $email !== '' ? ($customers[$email] ?? null) : null,
            'created_at' => $createdAt?->toDateTimeString(),
        ], []];
    }

    protected function parseDate(string $value): ?Carbon
    {
        foreach (['Y-m-d', 'Y-m-d H:i', 'Y-m-d H:i:s', 'd/m/Y', 'd-m-Y', 'd.m.Y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);

                if ($date && $date->format($format) === $value) {
                    return $date;
                }
            } catch (Throwable) {
                // Try the next format.
            }
        }

        return null;
    }

    protected function clean(mixed $value): string
    {
        $value = (string) $value;

        if (! mb_check_encoding($value, 'UTF-8')) {
            // Older Excel exports are Windows-1252.
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        return trim($value);
    }

    protected function stripBom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }

    protected function fileError(array $result, string $message): array
    {
        $result['errors'] = [0 => [$message]];
        $result['rows'] = [];

        return $result;
    }
}
