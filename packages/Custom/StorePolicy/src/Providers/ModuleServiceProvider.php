<?php

namespace Custom\StorePolicy\Providers;

use Custom\StorePolicy\Models\PolicyGeneration;
use Custom\StorePolicy\Models\PolicySetting;
use Webkul\Core\Providers\CoreModuleServiceProvider;

class ModuleServiceProvider extends CoreModuleServiceProvider
{
    protected $models = [
        PolicySetting::class,
        PolicyGeneration::class,
    ];
}
