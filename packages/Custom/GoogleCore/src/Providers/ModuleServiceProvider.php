<?php

namespace Custom\GoogleCore\Providers;

use Custom\GoogleCore\Models\AuditLog;
use Webkul\Core\Providers\CoreModuleServiceProvider;

class ModuleServiceProvider extends CoreModuleServiceProvider
{
    protected $models = [
        AuditLog::class,
    ];
}
