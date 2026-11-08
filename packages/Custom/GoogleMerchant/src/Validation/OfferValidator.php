<?php

namespace Custom\GoogleMerchant\Validation;

use Custom\GoogleMerchant\Mapping\MappedOffer;
use Custom\GoogleMerchant\Models\GmcTarget;
use Custom\GoogleMerchant\Support\StoreSettings;

/**
 * Checks an offer before it is sent. Messages name the store field to fix.
 */
class OfferValidator
{
    public const OFFER_ID_LIMIT = 50;

    public function __construct(protected StoreSettings $store) {}

    /**
     * @return array<string, string> Google field => admin-readable message
     */
    public function validate(MappedOffer $offer, GmcTarget $target): array
    {
        $a = $offer->attributes();
        $errors = [];

        $msg = fn (string $key, array $replace = []) => trans("google_merchant::app.validation.$key", $replace);

        $sku = $offer->offerId;

        if ($sku === '') {
            $errors['offerId'] = $msg('sku-missing');
        } elseif (mb_strlen($sku) > self::OFFER_ID_LIMIT) {
            $errors['offerId'] = $msg('sku-too-long', ['sku' => $sku, 'limit' => self::OFFER_ID_LIMIT]);
        } elseif ($sku !== trim($sku) || preg_match('/[\x00-\x1F\x7F]/', $sku)) {
            $errors['offerId'] = $msg('sku-whitespace', ['sku' => $sku]);
        }

        foreach (['title' => 'title-missing', 'description' => 'description-missing'] as $field => $key) {
            if (empty($a[$field])) {
                $errors[$field] = $msg($key);
            }
        }

        foreach (['link' => 'link-missing', 'imageLink' => 'image-missing'] as $field => $key) {
            if (empty($a[$field])) {
                $errors[$field] = $msg($key);
            } elseif (! $this->store->isPublicHttps($a[$field])) {
                $errors[$field] = $msg('public-https', ['url' => $this->store->websiteUrl($target->channel_id)]);
            }
        }

        if (empty($a['price']['amountMicros']) || (int) $a['price']['amountMicros'] <= 0) {
            $errors['price'] = $msg('price-missing');
        }

        $identifierExists = ($a['identifierExists'] ?? true) !== false;
        $gtin = $a['gtins'][0] ?? null;

        if (! $identifierExists) {
            if ($gtin !== null) {
                $errors['gtins'] = $msg('identifier-contradiction');
            }
        } else {
            if ($gtin !== null && ! Gtin::isValid($gtin)) {
                $errors['gtins'] = $msg('gtin-invalid', ['gtin' => $gtin]);
            } elseif ($gtin === null && (empty($a['brand']) || empty($a['mpn']))) {
                $errors['gtins'] = $msg('identifiers-missing');
            }

            if (empty($a['brand'])) {
                $errors['brand'] = $msg('brand-missing');
            }
        }

        if ($this->isApparel($a) && in_array('US', $target->countries ?? [])) {
            foreach (['color', 'size', 'gender', 'ageGroup'] as $field) {
                if (empty($a[$field])) {
                    $errors[$field] = $msg('apparel-'.$field);
                }
            }
        }

        return $errors;
    }

    protected function isApparel(array $attributes): bool
    {
        $category = (string) ($attributes['googleProductCategory'] ?? '');

        return $category !== '' && in_array($category, array_map('strval', config('google_merchant.apparel_category_ids', [])), true);
    }
}
