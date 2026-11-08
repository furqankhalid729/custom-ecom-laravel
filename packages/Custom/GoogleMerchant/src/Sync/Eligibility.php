<?php

namespace Custom\GoogleMerchant\Sync;

use Custom\GoogleMerchant\Mapping\MappedOffer;
use Custom\GoogleMerchant\Mapping\ProductDataResolver;
use Custom\GoogleMerchant\Models\GmcTarget;
use Custom\GoogleMerchant\Support\Settings;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductProxy;

/**
 * Whether a store product belongs in Google at all. Excluded products are
 * removed from Google (when they were there), not left to go stale.
 */
class Eligibility
{
    public function __construct(
        protected Settings $settings,
        protected ProductDataResolver $data,
    ) {}

    /**
     * Null when eligible, else one of: disabled_globally, sync_off, type_excluded,
     * product_disabled, not_visible, category_excluded.
     */
    public function reason(Product $root, GmcTarget $target): ?string
    {
        $this->data->forScope($target->channel_id, $target->locale);

        if (! $this->settings->enabled()) {
            return 'disabled_globally';
        }

        if (! $this->data->bool($root, 'gmc_sync', true)) {
            return 'sync_off';
        }

        if (in_array($root->type, $this->settings->list('settings.excluded_types'), true)) {
            return 'type_excluded';
        }

        if ($this->settings->bool('settings.exclude_disabled', true) && ! $this->data->bool($root, 'status', false)) {
            return 'product_disabled';
        }

        if ($this->settings->bool('settings.exclude_not_visible', true) && ! $this->data->bool($root, 'visible_individually', false)) {
            return 'not_visible';
        }

        $categoryIds = DB::table('product_categories')->where('product_id', $root->id)->pluck('category_id');

        if ($categoryIds->isNotEmpty() && DB::table('gmc_category_mappings')->whereIn('category_id', $categoryIds)->where('excluded', true)->exists()) {
            return 'category_excluded';
        }

        return null;
    }

    /**
     * Drops offers of variants that are disabled or switched off individually.
     *
     * @param  MappedOffer[]  $offers
     * @return MappedOffer[]
     */
    public function eligibleOffers(array $offers, GmcTarget $target): array
    {
        $this->data->forScope($target->channel_id, $target->locale);

        $products = ProductProxy::modelClass()::with('attribute_values')
            ->whereIn('id', array_map(fn ($o) => $o->productId, $offers))
            ->get()
            ->keyBy('id');

        return array_values(array_filter($offers, function (MappedOffer $offer) use ($products) {
            if ($offer->productId === $offer->rootProductId) {
                return true;
            }

            $variant = $products[$offer->productId] ?? null;

            return $variant
                && $this->data->bool($variant, 'gmc_sync', true)
                && (! $this->settings->bool('settings.exclude_disabled', true) || $this->data->bool($variant, 'status', false));
        }));
    }

    /**
     * 'out_of_stock_delete' when the store removes sold-out products from Google and every offer is sold out.
     *
     * @param  MappedOffer[]  $offers
     */
    public function stockReason(array $offers): ?string
    {
        if ($this->settings->get('settings.out_of_stock_action', 'keep') !== 'delete' || ! $offers) {
            return null;
        }

        foreach ($offers as $offer) {
            if (($offer->attributes()['availability'] ?? null) !== 'OUT_OF_STOCK') {
                return null;
            }
        }

        return 'out_of_stock_delete';
    }
}
