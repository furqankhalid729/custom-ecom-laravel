<?php

namespace Custom\GoogleMerchant\Providers;

use Custom\GoogleCore\Integrations\IntegrationRegistry;
use Custom\GoogleMerchant\Auth\AccessTokenProvider;
use Custom\GoogleMerchant\Auth\TokenManager;
use Custom\GoogleMerchant\MerchantIntegration;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\Core\Http\Middleware\NoCacheMiddleware;

class GoogleMerchantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/google_merchant.php', 'google_merchant');

        $this->mergeConfigFrom(__DIR__.'/../Config/menu.php', 'menu.admin');

        $this->mergeConfigFrom(__DIR__.'/../Config/acl.php', 'acl');

        $this->mergeConfigFrom(__DIR__.'/../Config/system.php', 'core');

        $this->app->bind(AccessTokenProvider::class, TokenManager::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'google_merchant');

        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'google_merchant');

        $this->app->register(ModuleServiceProvider::class);

        if (file_exists($routes = __DIR__.'/../Routes/admin-routes.php')) {
            Route::middleware(['web', 'admin', NoCacheMiddleware::class])
                ->prefix(config('app.admin_url'))
                ->group($routes);
        }

        $this->app->make(IntegrationRegistry::class)->register($this->app->make(MerchantIntegration::class));
    }
}
