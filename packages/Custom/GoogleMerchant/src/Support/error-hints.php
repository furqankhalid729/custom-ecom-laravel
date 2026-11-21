<?php

/*
| Google field path / issue code → hint key in google_merchant::app.hints.fields.*
| Unknown fields and codes fall back to Google's own message.
*/
return [
    'fields' => [
        'offerId' => 'sku',
        'productAttributes.title' => 'title',
        'productAttributes.description' => 'description',
        'productAttributes.link' => 'link',
        'productAttributes.imageLink' => 'image',
        'productAttributes.additionalImageLinks' => 'image',
        'productAttributes.price' => 'price',
        'productAttributes.salePrice' => 'price',
        'productAttributes.salePriceEffectiveDate' => 'price',
        'productAttributes.availability' => 'availability',
        'productAttributes.brand' => 'brand',
        'productAttributes.gtins' => 'gtin',
        'productAttributes.mpn' => 'identifiers',
        'productAttributes.identifierExists' => 'identifiers',
        'productAttributes.googleProductCategory' => 'category',
        'productAttributes.shippingWeight' => 'weight',
        'productAttributes.color' => 'apparel',
        'productAttributes.size' => 'apparel',
        'productAttributes.gender' => 'apparel',
        'productAttributes.ageGroup' => 'apparel',
    ],

    /* Store-side validation keys (gmc_products.validation_errors) use bare field names. */
    'aliases' => [
        'gtins' => 'productAttributes.gtins',
    ],

    /* Google item-level issue `attribute` values (snake_case) → hint key. */
    'issue_attributes' => [
        'offer_id' => 'sku',
        'title' => 'title',
        'description' => 'description',
        'link' => 'link',
        'image_link' => 'image',
        'additional_image_link' => 'image',
        'price' => 'price',
        'sale_price' => 'price',
        'availability' => 'availability',
        'brand' => 'brand',
        'gtin' => 'gtin',
        'mpn' => 'identifiers',
        'identifier_exists' => 'identifiers',
        'google_product_category' => 'category',
        'shipping_weight' => 'weight',
        'color' => 'apparel',
        'size' => 'apparel',
        'gender' => 'apparel',
        'age_group' => 'apparel',
    ],

    /* Issue codes without an attribute. */
    'issue_codes' => [
        'missing_gtin' => 'gtin',
        'image_too_small' => 'image',
        'landing_page_error' => 'link',
        'mismatched_price' => 'price',
        'mismatched_availability' => 'availability',
    ],

    'http' => [401, 403, 404, 429, 500],
];
