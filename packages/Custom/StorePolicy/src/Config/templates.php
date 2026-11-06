<?php

/*
|--------------------------------------------------------------------------
| Policy templates
|--------------------------------------------------------------------------
|
| Each template writes into the CMS page with the same url_key. "depends" lists
| the settings paths (data_get syntax, * allowed) whose change makes the page
| out of date.
|
*/

return [
    'return-policy' => [
        'url_key'          => 'return-policy',
        'title'            => 'Return Policy',
        'meta_description' => 'How to return items, the return window, and how refunds work.',
        'depends'          => ['business', 'regions.*.enabled', 'regions.*.countries', 'regions.*.returns', 'terms.effective_date'],
    ],

    'refund-policy' => [
        'url_key'          => 'refund-policy',
        'title'            => 'Refund Policy',
        'meta_description' => 'When and how refunds are issued.',
        'depends'          => ['business', 'regions.*.enabled', 'regions.*.returns', 'terms.effective_date'],
    ],

    'shipping-policy' => [
        'url_key'          => 'shipping-policy',
        'title'            => 'Shipping Policy',
        'meta_description' => 'Where we ship, shipping costs, processing and delivery times.',
        'depends'          => ['business', 'regions.*.enabled', 'regions.*.countries', 'regions.*.currency', 'regions.*.shipping', 'terms.effective_date'],
    ],

    'payment-policy' => [
        'url_key'          => 'payment-policy',
        'title'            => 'Payment Policy',
        'meta_description' => 'Accepted payment methods, currencies and when you are charged.',
        'depends'          => ['business', 'payment', 'regions.*.enabled', 'regions.*.currency', 'terms.effective_date'],
    ],

    'privacy-policy' => [
        'url_key'          => 'privacy-policy',
        'title'            => 'Privacy Policy',
        'meta_description' => 'What personal data we collect, how we use it, and your rights.',
        'depends'          => ['business', 'privacy', 'regions.*.enabled', 'terms.effective_date'],
    ],

    'terms-conditions' => [
        'url_key'          => 'terms-conditions',
        'title'            => 'Terms & Conditions',
        'meta_description' => 'The terms that apply when you buy from our store.',
        'depends'          => ['business', 'terms', 'regions.*.enabled'],
    ],

    'about-us' => [
        'url_key'          => 'about-us',
        'title'            => 'About Us',
        'meta_description' => 'Who we are and how to contact us.',
        'depends'          => ['business', 'regions.*.enabled'],
    ],
];
