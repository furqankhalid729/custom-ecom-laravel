<?php

namespace Custom\GoogleMerchant\Listeners;

use Custom\GoogleMerchant\Sync\SyncDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Bagisto imports fire one event per batch, not per product.
 */
class ImportListener
{
    public const DELAY_SECONDS = 60;

    public function __construct(protected SyncDispatcher $dispatcher) {}

    public function handle($batch): void
    {
        try {
            if (($batch->import->type ?? null) !== 'products') {
                return;
            }

            $skus = collect($batch->data ?? [])->pluck('sku')->filter()->unique()->values();

            if ($skus->isEmpty()) {
                return;
            }

            $ids = DB::table('products')->whereIn('sku', $skus)->pluck('id')->all();

            if ($ids) {
                $this->dispatcher->queue($ids, 'import', self::DELAY_SECONDS);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
