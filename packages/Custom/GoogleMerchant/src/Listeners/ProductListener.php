<?php

namespace Custom\GoogleMerchant\Listeners;

use Custom\GoogleMerchant\Enums\Operation;
use Custom\GoogleMerchant\Enums\SyncState;
use Custom\GoogleMerchant\Repositories\GmcProductRepository;
use Custom\GoogleMerchant\Support\Settings;
use Custom\GoogleMerchant\Sync\SkuChangeTracker;
use Custom\GoogleMerchant\Sync\SyncDispatcher;
use Custom\GoogleMerchant\Sync\SyncLogger;
use Illuminate\Support\Facades\DB;

/**
 * Admin product create / update / delete → queue work. Never throws into Bagisto.
 */
class ProductListener
{
    public function __construct(
        protected SyncDispatcher $dispatcher,
        protected SkuChangeTracker $skus,
        protected GmcProductRepository $ledger,
        protected SyncLogger $logger,
        protected Settings $settings,
    ) {}

    public function beforeUpdate($productId): void
    {
        $this->safely(fn () => $this->skus->remember((int) $productId, DB::table('products')->where('id', $productId)->value('sku')));
    }

    public function afterSave($product): void
    {
        $this->safely(function () use ($product) {
            if (! $product?->id) {
                return;
            }

            $oldSku = $this->skus->pull($product->id);

            if ($oldSku !== null && $oldSku !== $product->sku) {
                // The old offer stays attached to the root; the root's sync deletes it before inserting the new one.
                $this->ledger->query()->where('offer_id', $oldSku)->where('product_id', $product->id)->get()
                    ->each(fn ($row) => $this->logger->log($row, Operation::Upsert, 'skipped', message: "sku_changed: {$oldSku} → {$product->sku}"));
            }

            $this->dispatcher->queue([$product->id], 'save');
        });
    }

    /**
     * The product row is gone after delete, so its offers are captured now.
     */
    public function beforeDelete($productId): void
    {
        $this->safely(function () use ($productId) {
            if (! $this->settings->enabled()) {
                return;
            }

            $productId = (int) $productId;

            $rows = $this->ledger->query()
                ->where(fn ($q) => $q->where('product_id', $productId)->orWhere('root_product_id', $productId))
                ->whereNotIn('state', [SyncState::Deleted->value, SyncState::Deleting->value])
                ->get();

            foreach ($rows as $row) {
                if ($row->last_synced_at && $row->product_input_name) {
                    $row->forceFill(['state' => SyncState::Deleting->value])->save();

                    $this->dispatcher->queueDelete($row->id);
                } else {
                    $row->forceFill(['state' => SyncState::Deleted->value])->save();
                }
            }

            $others = array_values(array_diff($this->dispatcher->rootsFor([$productId]), [$productId]));

            if ($others) {
                $this->dispatcher->queue($others, 'delete');
            }
        });
    }

    protected function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
