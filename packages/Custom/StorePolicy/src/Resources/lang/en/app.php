<?php

return [
    'menu' => [
        'pages'          => 'Pages',
        'store-policies' => 'Store Policies',
    ],

    'acl' => [
        'store-policies' => 'Store Policies',
        'edit'           => 'Edit Settings',
        'generate'       => 'Generate Pages',
        'restore'        => 'Restore Page',
    ],

    'index' => [
        'title'            => 'Store Policies',
        'intro'            => 'Enter your store details once, then generate your policy pages. Generated pages stay editable in CMS → Pages.',
        'disclaimer'       => 'Generated text is a starting point, not legal advice. Have the EU and US wording reviewed before launch.',
        'save-btn'         => 'Save Settings',
        'update-success'   => 'Policy settings saved.',
        'missing-settings' => 'Fill in the required settings before generating: :fields',
        'generate-success' => 'Generated: :pages',
        'generate-skipped' => 'Not replaced because they have manual changes (tick "Replace my manual changes" to overwrite): :pages',
        'restore-success'  => 'Restored the previous version of :page.',
        'restore-failed'   => 'There is no previous version to restore.',
        'out-of-date'      => 'Your changes affect these pages. They are ticked below — press Generate to update them:',
        'not-saved'        => 'Settings have not been saved yet. The values below are suggestions from your channel.',
    ],

    'sections' => [
        'business' => 'Business details',
        'regions'  => 'Selling regions',
        'returns'  => 'Returns & refunds',
        'shipping' => 'Shipping',
        'payment'  => 'Payment',
        'privacy'  => 'Privacy',
        'terms'    => 'Terms',
    ],

    'fields' => [
        'legal_name'          => 'Legal business name',
        'trading_name'        => 'Store / trading name',
        'logo_url'            => 'Logo URL',
        'street'              => 'Street address',
        'city'                => 'City',
        'region'              => 'State / region',
        'postcode'            => 'Postal code',
        'country'             => 'Country',
        'email'               => 'Customer support email',
        'phone'               => 'Phone',
        'hours'               => 'Customer service hours',
        'registration_number' => 'Company registration number',
        'vat_number'          => 'VAT / tax number',
        'website_url'         => 'Website URL',

        'region_enabled' => 'Sell to this region',
        'countries'      => 'Countries you ship to',
        'currency'       => 'Currency',

        'returns_accepted'        => 'Accept returns',
        'window_days'             => 'Return window (days after delivery)',
        'conditions'              => 'Items must be returned',
        'conditions_note'         => 'Extra return conditions',
        'non_returnable'          => 'Items that cannot be returned',
        'method'                  => 'Return method',
        'return_shipping_paid_by' => 'Who pays for return shipping',
        'restocking_fee_percent'  => 'Restocking fee (%)',
        'refund_method'           => 'Refund method',
        'refund_days'             => 'Refund issued within (days)',
        'exchanges'               => 'Offer exchanges',
        'defect_report_days'      => 'Report faulty items within (days)',
        'defect_resolution'       => 'How faulty items are resolved',

        'handling_min_days' => 'Processing time — minimum business days',
        'handling_max_days' => 'Processing time — maximum business days',
        'cutoff_time'       => 'Daily order cut-off time (HH:MM)',
        'methods'           => 'Shipping methods',
        'method_name'       => 'Name',
        'method_carrier'    => 'Carrier',
        'method_cost'       => 'Cost',
        'method_free_over'  => 'Free over',
        'method_min_days'   => 'Min days',
        'method_max_days'   => 'Max days',
        'add_method'        => 'Add method',
        'remove_method'     => 'Remove',
        'tracking'          => 'Tracking number provided',
        'po_boxes'          => 'Deliver to P.O. boxes',
        'excluded_areas'    => 'Areas you do not ship to',
        'lost_damaged_note' => 'Lost or damaged parcels',
        'shipping_notice'   => 'These must match what checkout charges — Merchant Center compares them.',

        'payment_methods' => 'Accepted payment methods (one per line)',
        'charged_at'      => 'When the customer is charged',

        'data_collected'    => 'Data you collect (one per line)',
        'third_parties'     => 'Who you share data with (one per line)',
        'uses_cookies'         => 'The site uses cookies',
        'sells_or_shares'      => 'We share data with advertising partners (ad pixels, retargeting)',
        'sells_or_shares_hint' => 'Tick this if you use Google Ads, Meta pixel or similar tracking. It changes the privacy page wording, including the California section.',
        'retention'         => 'How long you keep data',
        'contact_email'     => 'Privacy contact email (defaults to support email)',
        'eu_representative' => 'EU representative / data protection officer (optional)',

        'governing_law'  => 'Governing law (country or state)',
        'minimum_age'    => 'Minimum age to order',
        'effective_date' => 'Policy effective date (YYYY-MM-DD)',
    ],

    'options' => [
        'unused'             => 'Unused',
        'original_packaging' => 'In original packaging',
        'tags_attached'      => 'With tags attached',
        'mail'               => 'By mail',
        'store'              => 'In store',
        'both'               => 'By mail or in store',
        'customer'           => 'Customer pays',
        'store_pays'         => 'We pay (prepaid label)',
        'free'               => 'Free returns',
        'original'           => 'Original payment method',
        'store_credit'       => 'Store credit',
        'customer_choice'    => "Customer's choice",
        'order'              => 'When the order is placed',
        'shipment'           => 'When the order ships',
    ],

    'status' => [
        'title'              => 'Policy pages',
        'page'               => 'Page',
        'state'              => 'Status',
        'generated-at'       => 'Last generated',
        'view'               => 'View',
        'edit'               => 'Edit',
        'confirm-overwrite'  => 'Replace my manual changes',
        'restore'            => 'Restore previous version',
        'generate-btn'       => 'Generate selected pages',
        'never'              => 'Never',
        'not_generated'      => 'Not generated',
        'up_to_date'         => 'Up to date',
        'out_of_date'        => 'Out of date',
        'edited'             => 'Edited by hand',
        'out_of_date_edited' => 'Out of date + edited by hand',
    ],

    'readiness' => [
        'title' => 'Google Merchant Center readiness',

        'business_contact' => [
            'label' => 'Business name, address, email and phone filled in',
            'fail'  => 'Missing:',
        ],

        'return_policy' => [
            'label' => 'Return policy generated and published',
            'fail'  => 'Generate the Return Policy page.',
        ],

        'shipping_policy' => [
            'label' => 'Shipping policy generated with costs and delivery times for every region',
            'fail'  => 'Generate the Shipping Policy and add at least one shipping method with cost and delivery time for:',
        ],

        'other_pages' => [
            'label' => 'Privacy, terms and payment pages generated',
            'fail'  => 'Not generated yet:',
        ],

        'footer_links' => [
            'label' => 'All policy pages linked in the footer',
            'fail'  => 'Not linked:',
        ],

        'structured_data' => [
            'label' => 'Product structured data shows offers',
            'fail'  => 'Turn on Configure → Catalog → Rich Snippets → Products: "Enable" and "Show Offers".',
        ],

        'other_locales' => [
            'label' => 'No placeholder text left in other languages',
            'fail'  => 'Generated pages still have different (placeholder) text in these languages — edit or remove those translations:',
        ],
    ],
];
