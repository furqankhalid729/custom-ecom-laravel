<?php

/*
| Google field path / issue code → hint key in google_merchant::app.hints.fields.*
| Unknown fields and codes fall back to Google's own message.
*/
return [
    'fields' => [
        'offerId'                                 => 'sku',
        'productAttributes.title'                 => 'title',
        'productAttributes.description'           => 'description',
        'productAttributes.link'                  => 'link',
        'productAttributes.imageLink'             => 'image',
        'productAttributes.additionalImageLinks'  => 'image',
        'productAttributes.price'                 => 'price',
        'productAttributes.salePrice'             => 'price',
        'productAttributes.salePriceEffectiveDate' => 'price',
        'productAttributes.availability'          => 'availability',
        'productAttributes.brand'                 => 'brand',
        'productAttributes.gtins'                 => 'gtin',
        'productAttributes.mpn'                   => 'identifiers',
        'productAttributes.identifierExists'      => 'identifiers',
        'productAttributes.googleProductCategory' => 'category',
        'productAttributes.shippingWeight'        => 'weight',
        'productAttributes.color'                 => 'apparel',
        'productAttributes.size'                  => 'apparel',
        'productAttributes.gender'                => 'apparel',
        'productAttributes.ageGroup'              => 'apparel',
    ],

    /* Store-side validation keys (gmc_products.validation_errors) use bare field names. */
    'aliases' => [
        'gtins' => 'productAttributes.gtins',
    ],

    'http' => [401, 403, 404, 429, 500],
];
