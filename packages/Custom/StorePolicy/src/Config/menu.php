<?php

/*
| Gives the existing CMS menu item two children: the stock pages list and Store Policies.
*/
return [
    [
        'key'   => 'cms.pages',
        'name'  => 'store_policy::app.menu.pages',
        'route' => 'admin.cms.index',
        'sort'  => 1,
        'icon'  => '',
    ], [
        'key'   => 'cms.store_policies',
        'name'  => 'store_policy::app.menu.store-policies',
        'route' => 'admin.cms.store_policies.index',
        'sort'  => 2,
        'icon'  => '',
    ],
];
