<?php

namespace Custom\GoogleMerchant\Providers;

use Custom\GoogleMerchant\Models\GmcAccount;
use Custom\GoogleMerchant\Models\GmcAttributeMapping;
use Custom\GoogleMerchant\Models\GmcCategoryMapping;
use Custom\GoogleMerchant\Models\GmcProduct;
use Custom\GoogleMerchant\Models\GmcRun;
use Custom\GoogleMerchant\Models\GmcSyncLog;
use Custom\GoogleMerchant\Models\GmcTarget;
use Webkul\Core\Providers\CoreModuleServiceProvider;

class ModuleServiceProvider extends CoreModuleServiceProvider
{
    protected $models = [
        GmcAccount::class,
        GmcTarget::class,
        GmcAttributeMapping::class,
        GmcCategoryMapping::class,
        GmcProduct::class,
        GmcSyncLog::class,
        GmcRun::class,
    ];
}
