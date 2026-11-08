<?php

$t = 'google_merchant::app.configuration';

return [
    [
        'key'  => 'google_merchant',
        'name' => "$t.title",
        'info' => "$t.info",
        'sort' => 9,
    ], [
        'key'  => 'google_merchant.general',
        'name' => "$t.general.title",
        'info' => "$t.general.info",
        'icon' => 'settings/store.svg',
        'sort' => 1,
    ], [
        'key'    => 'google_merchant.general.settings',
        'name'   => "$t.settings.title",
        'info'   => "$t.settings.info",
        'sort'   => 1,
        'fields' => [
            ['name' => 'enabled', 'title' => "$t.settings.enabled", 'type' => 'boolean', 'default' => false],
            ['name' => 'auto_sync', 'title' => "$t.settings.auto-sync", 'type' => 'boolean', 'default' => true],
            ['name' => 'sync_delay', 'title' => "$t.settings.sync-delay", 'type' => 'number', 'default' => 30, 'validation' => 'numeric|min:0|max:3600'],
            [
                'name'    => 'excluded_types',
                'title'   => "$t.settings.excluded-types",
                'type'    => 'multiselect',
                'default' => 'virtual,downloadable,booking',
                'options' => [
                    ['title' => "$t.settings.type-virtual", 'value' => 'virtual'],
                    ['title' => "$t.settings.type-downloadable", 'value' => 'downloadable'],
                    ['title' => "$t.settings.type-booking", 'value' => 'booking'],
                ],
            ],
            ['name' => 'exclude_disabled', 'title' => "$t.settings.exclude-disabled", 'type' => 'boolean', 'default' => true],
            ['name' => 'exclude_not_visible', 'title' => "$t.settings.exclude-not-visible", 'type' => 'boolean', 'default' => true],
            [
                'name'    => 'out_of_stock_action',
                'title'   => "$t.settings.out-of-stock-action",
                'type'    => 'select',
                'default' => 'keep',
                'options' => [
                    ['title' => "$t.settings.oos-keep", 'value' => 'keep'],
                    ['title' => "$t.settings.oos-delete", 'value' => 'delete'],
                ],
            ],
            ['name' => 'default_brand', 'title' => "$t.settings.default-brand", 'type' => 'text', 'default' => ''],
            ['name' => 'rate_limit_per_minute', 'title' => "$t.settings.rate-limit", 'type' => 'number', 'default' => 60, 'validation' => 'numeric|min:1|max:6000'],
            ['name' => 'log_retention_days', 'title' => "$t.settings.log-retention", 'type' => 'number', 'default' => 30, 'validation' => 'numeric|min:1|max:365'],
            ['name' => 'notify_email', 'title' => "$t.settings.notify-email", 'type' => 'text', 'default' => '', 'validation' => 'nullable|email'],
            ['name' => 'notify_on_failure', 'title' => "$t.settings.notify-on-failure", 'type' => 'boolean', 'default' => true],
        ],
    ], [
        'key'    => 'google_merchant.general.store',
        'name'   => "$t.store.title",
        'info'   => "$t.store.info",
        'sort'   => 2,
        'fields' => [
            ['name' => 'website_url', 'title' => "$t.store.website-url", 'type' => 'text', 'default' => '', 'validation' => 'nullable|url'],
            ['name' => 'country', 'title' => "$t.store.country", 'type' => 'text', 'default' => 'US', 'validation' => 'required|size:2'],
            ['name' => 'language', 'title' => "$t.store.language", 'type' => 'text', 'default' => 'en', 'validation' => 'required'],
            ['name' => 'currency', 'title' => "$t.store.currency", 'type' => 'text', 'default' => 'USD', 'validation' => 'required|size:3'],
            ['name' => 'return_policy_url', 'title' => "$t.store.return-policy-url", 'type' => 'text', 'default' => '', 'validation' => 'nullable|url'],
            ['name' => 'refund_policy_url', 'title' => "$t.store.refund-policy-url", 'type' => 'text', 'default' => '', 'validation' => 'nullable|url'],
            ['name' => 'terms_url', 'title' => "$t.store.terms-url", 'type' => 'text', 'default' => '', 'validation' => 'nullable|url'],
            ['name' => 'privacy_url', 'title' => "$t.store.privacy-url", 'type' => 'text', 'default' => '', 'validation' => 'nullable|url'],
            ['name' => 'shipping_note', 'title' => "$t.store.shipping-note", 'type' => 'textarea', 'default' => ''],
            ['name' => 'tax_note', 'title' => "$t.store.tax-note", 'type' => 'textarea', 'default' => ''],
        ],
    ],
];
