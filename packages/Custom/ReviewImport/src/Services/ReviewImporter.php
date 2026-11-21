<?php

namespace Custom\ReviewImport\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Product\Models\ProductReview;

/**
 * Saves parsed review rows. A row that matches an existing review (same product, name,
 * rating and text) is skipped, so running the same file twice does not duplicate reviews.
 */
class ReviewImporter
{
    /**
     * @return array{imported: int, duplicates: int}
     */
    public function import(array $rows): array
    {
        $imported = 0;
        $duplicates = 0;
        $touchedProducts = [];

        DB::transaction(function () use ($rows, &$imported, &$duplicates, &$touchedProducts) {
            foreach ($rows as $row) {
                if ($this->exists($row)) {
                    $duplicates++;

                    continue;
                }

                $review = new ProductReview([
                    'product_id' => $row['product_id'],
                    'customer_id' => $row['customer_id'],
                    'name' => $row['name'],
                    'title' => $row['title'],
                    'rating' => $row['rating'],
                    'comment' => $row['comment'],
                    'status' => $row['status'],
                ]);

                if ($row['created_at']) {
                    $review->created_at = $row['created_at'];
                    $review->updated_at = $row['created_at'];
                }

                $review->save();

                $imported++;
                $touchedProducts[$row['product_id']] = $review;
            }
        });

        // Clears the full-page cache of each product page so the new reviews show straight away.
        foreach ($touchedProducts as $review) {
            Event::dispatch('customer.review.update.after', $review);
        }

        return ['imported' => $imported, 'duplicates' => $duplicates];
    }

    protected function exists(array $row): bool
    {
        return ProductReview::query()
            ->where('product_id', $row['product_id'])
            ->where('name', $row['name'])
            ->where('rating', $row['rating'])
            ->where('title', $row['title'])
            ->where(fn ($query) => $row['comment'] === null
                ? $query->whereNull('comment')
                : $query->where('comment', $row['comment']))
            ->exists();
    }
}
