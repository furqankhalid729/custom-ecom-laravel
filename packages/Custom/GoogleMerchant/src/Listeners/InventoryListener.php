<?php

namespace Custom\GoogleMerchant\Listeners;

use Custom\GoogleMerchant\Sync\SyncDispatcher;

/**
 * Orders, cancellations, refunds and shipments change salable stock.
 */
class InventoryListener
{
    public function __construct(protected SyncDispatcher $dispatcher) {}

    /** @param  object  $entity  order / refund / shipment with items */
    public function handle($entity): void
    {
        try {
            $ids = [];

            foreach ($entity->items ?? [] as $item) {
                $ids[] = $item->product_id ?? null;
                $ids[] = $item->child->product_id ?? null;

                foreach ($item->children ?? [] as $child) {
                    $ids[] = $child->product_id ?? null;
                }
            }

            $ids = array_values(array_filter(array_unique($ids)));

            if ($ids) {
                $this->dispatcher->queue($ids, 'stock');
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
