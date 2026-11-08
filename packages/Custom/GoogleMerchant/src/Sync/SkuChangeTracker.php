<?php

namespace Custom\GoogleMerchant\Sync;

/**
 * Remembers SKUs seen in catalog.product.update.before (request-scoped).
 */
class SkuChangeTracker
{
    /** @var array<int, string> */
    protected array $skus = [];

    public function remember(int $productId, ?string $sku): void
    {
        if ($sku !== null) {
            $this->skus[$productId] = $sku;
        }
    }

    public function pull(int $productId): ?string
    {
        $sku = $this->skus[$productId] ?? null;

        unset($this->skus[$productId]);

        return $sku;
    }
}
