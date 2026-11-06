<?php

return [
    [
        'key'   => 'cms.store_policies',
        'name'  => 'store_policy::app.acl.store-policies',
        'route' => 'admin.cms.store_policies.index',
        'sort'  => 4,
    ], [
        'key'   => 'cms.store_policies.edit',
        'name'  => 'store_policy::app.acl.edit',
        'route' => 'admin.cms.store_policies.update',
        'sort'  => 1,
    ], [
        'key'   => 'cms.store_policies.generate',
        'name'  => 'store_policy::app.acl.generate',
        'route' => 'admin.cms.store_policies.generate',
        'sort'  => 2,
    ], [
        'key'   => 'cms.store_policies.restore',
        'name'  => 'store_policy::app.acl.restore',
        'route' => 'admin.cms.store_policies.restore',
        'sort'  => 3,
    ],
];
