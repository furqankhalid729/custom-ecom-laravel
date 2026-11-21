<?php

return [
    [
        'key' => 'customers.review_import',
        'name' => 'review_import::app.acl.import',
        'route' => 'admin.customers.reviews.import.index',
        'sort' => 5,
    ], [
        'key' => 'customers.review_import.create',
        'name' => 'review_import::app.acl.create',
        'route' => 'admin.customers.reviews.import.store',
        'sort' => 1,
    ],
];
