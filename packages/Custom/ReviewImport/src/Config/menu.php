<?php

/*
| Adds "Import Reviews" under the Customers menu, after Reviews.
*/
return [
    [
        'key' => 'customers.review_import',
        'name' => 'review_import::app.menu',
        'route' => 'admin.customers.reviews.import.index',
        'sort' => 5,
        'icon' => '',
    ],
];
