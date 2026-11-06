<?php

namespace Custom\StorePolicy\Seo;

use Webkul\Product\Helpers\SEO;

/**
 * Adds shipping details and return policies from the store policy settings
 * to the product Offer structured data. Unchanged until settings are saved.
 */
class ProductSeo extends SEO
{
    public function getProductOffers($product)
    {
        $offers = parent::getProductOffers($product);

        $merchant = app(MerchantJsonLd::class);

        $settings = $merchant->currentSettings();

        if (! $settings) {
            return $offers;
        }

        $price = isset($offers['price']) ? (float) $offers['price'] : null;

        if ($shippingDetails = $merchant->shippingDetails($settings, $price, $offers['priceCurrency'] ?? null)) {
            $offers['shippingDetails'] = $shippingDetails;
        }

        if ($returnPolicies = $merchant->returnPolicies($settings)) {
            $offers['hasMerchantReturnPolicy'] = $returnPolicies;
        }

        return $offers;
    }
}
