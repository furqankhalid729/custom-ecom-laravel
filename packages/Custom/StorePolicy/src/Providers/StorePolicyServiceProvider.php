<?php

namespace Custom\StorePolicy\Providers;

use Custom\StorePolicy\Seo\ProductSeo;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Webkul\Core\Http\Middleware\NoCacheMiddleware;
use Illuminate\Support\ServiceProvider;
use Webkul\Product\Helpers\SEO;
use Webkul\Theme\ViewRenderEventManager;

class StorePolicyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/templates.php', 'store_policy.templates');

        $this->mergeConfigFrom(__DIR__.'/../Config/menu.php', 'menu.admin');

        $this->mergeConfigFrom(__DIR__.'/../Config/acl.php', 'acl');

        $this->app->bind(SEO::class, ProductSeo::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'store_policy');

        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'store_policy');

        $this->app->register(ModuleServiceProvider::class);

        Route::middleware(['web', 'admin', NoCacheMiddleware::class])
            ->prefix(config('app.admin_url'))
            ->group(__DIR__.'/../Routes/admin-routes.php');

        Event::listen('bagisto.shop.layout.head.after', static function (ViewRenderEventManager $viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('store_policy::shop.organization-json-ld');
            $viewRenderEventManager->addTemplate('store_policy::shop.page-styles');
        });
    }
}
