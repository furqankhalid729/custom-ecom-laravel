<?php

/*
| Bagisto's Bouncer only checks routes listed here, so every route of the
| package has an entry (one route per key).
*/
$p = 'marketing.google_merchant';
$r = 'admin.google_merchant';
$t = 'google_merchant::app.acl';

return [
    ['key' => $p, 'name' => "$t.google-merchant", 'route' => "$r.overview", 'sort' => 4],
    ['key' => "$p.overview", 'name' => "$t.overview", 'route' => "$r.overview", 'sort' => 1],

    ['key' => "$p.products", 'name' => "$t.products", 'route' => "$r.products.index", 'sort' => 2],
    ['key' => "$p.products.sync", 'name' => "$t.sync", 'route' => "$r.products.sync", 'sort' => 1],
    ['key' => "$p.products.retry", 'name' => "$t.retry", 'route' => "$r.products.retry", 'sort' => 2],
    ['key' => "$p.products.mass_sync", 'name' => "$t.mass-sync", 'route' => "$r.products.mass_sync", 'sort' => 3],
    ['key' => "$p.products.mass_retry", 'name' => "$t.mass-retry", 'route' => "$r.products.mass_retry", 'sort' => 4],
    ['key' => "$p.products.product_sync", 'name' => "$t.product-sync", 'route' => "$r.product.sync", 'sort' => 5],
    ['key' => "$p.products.exclude", 'name' => "$t.exclude", 'route' => "$r.products.exclude", 'sort' => 6],
    ['key' => "$p.products.include", 'name' => "$t.include", 'route' => "$r.products.include", 'sort' => 7],
    ['key' => "$p.products.mass_exclude", 'name' => "$t.mass-exclude", 'route' => "$r.products.mass_exclude", 'sort' => 8],
    ['key' => "$p.products.remove", 'name' => "$t.remove", 'route' => "$r.products.remove", 'sort' => 9],

    ['key' => "$p.runs", 'name' => "$t.runs", 'route' => "$r.runs.index", 'sort' => 3],
    ['key' => "$p.runs.show", 'name' => "$t.view-run", 'route' => "$r.runs.show", 'sort' => 1],
    ['key' => "$p.runs.create", 'name' => "$t.start-run", 'route' => "$r.runs.store", 'sort' => 2],
    ['key' => "$p.runs.pause", 'name' => "$t.pause-run", 'route' => "$r.runs.pause", 'sort' => 3],
    ['key' => "$p.runs.resume", 'name' => "$t.resume-run", 'route' => "$r.runs.resume", 'sort' => 4],

    ['key' => "$p.connection", 'name' => "$t.connection", 'route' => "$r.connection.index", 'sort' => 4],
    ['key' => "$p.connection.oauth", 'name' => "$t.connect-google", 'route' => "$r.oauth.start", 'sort' => 1],
    ['key' => "$p.connection.callback", 'name' => "$t.connect-google", 'route' => "$r.oauth.callback", 'sort' => 2],
    ['key' => "$p.connection.service_account", 'name' => "$t.connect-service-account", 'route' => "$r.service_account", 'sort' => 3],
    ['key' => "$p.connection.merchant", 'name' => "$t.select-merchant", 'route' => "$r.merchant.select", 'sort' => 4],
    ['key' => "$p.connection.disconnect", 'name' => "$t.disconnect", 'route' => "$r.disconnect", 'sort' => 5],
];
