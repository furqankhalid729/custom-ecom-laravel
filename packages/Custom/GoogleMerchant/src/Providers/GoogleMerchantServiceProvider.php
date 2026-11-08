<?php

namespace Custom\GoogleMerchant\Providers;

use Custom\GoogleCore\Integrations\IntegrationRegistry;
use Custom\GoogleMerchant\Auth\AccessTokenProvider;
use Custom\GoogleMerchant\Auth\TokenManager;
use Custom\GoogleMerchant\Console\Commands\QueueRunCommand;
use Custom\GoogleMerchant\Console\Commands\RefreshCommand;
use Custom\GoogleMerchant\Console\Commands\RetryFailedCommand;
use Custom\GoogleMerchant\Console\Commands\SyncCommand;
use Custom\GoogleMerchant\Console\GmcSchedule;
use Custom\GoogleMerchant\Listeners\ImportListener;
use Illuminate\Console\Scheduling\Schedule;
use Webkul\Theme\ViewRenderEventManager;
use Custom\GoogleMerchant\Listeners\InventoryListener;
use Custom\GoogleMerchant\Listeners\ProductListener;
use Custom\GoogleMerchant\MerchantIntegration;
use Custom\GoogleMerchant\Sync\SkuChangeTracker;
use Illuminate\Support\Facades\Event;
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

        $this->app->scoped(SkuChangeTracker::class);
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

        $this->registerListeners();

        if ($this->app->runningInConsole()) {
            $this->commands([
                QueueRunCommand::class,
                SyncCommand::class,
                RefreshCommand::class,
                RetryFailedCommand::class,
            ]);
        }

        $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $this->app->make(GmcSchedule::class)->define($schedule));
    }

    protected function registerListeners(): void
    {
        Event::listen('catalog.product.update.before', [ProductListener::class, 'beforeUpdate']);
        Event::listen('catalog.product.create.after', [ProductListener::class, 'afterSave']);
        Event::listen('catalog.product.update.after', [ProductListener::class, 'afterSave']);
        Event::listen('catalog.product.delete.before', [ProductListener::class, 'beforeDelete']);

        foreach (['checkout.order.save.after', 'sales.order.cancel.after', 'sales.refund.save.after', 'sales.shipment.save.after'] as $event) {
            Event::listen($event, [InventoryListener::class, 'handle']);
        }

        Event::listen('data_transfer.imports.batch.import.after', [ImportListener::class, 'handle']);

        Event::listen('bagisto.admin.catalog.product.edit.after', static function (ViewRenderEventManager $manager) {
            if (bouncer()->hasPermission('marketing.google_merchant.products')) {
                $manager->addTemplate('google_merchant::admin.partials.product-panel');
            }
        });

        Event::listen('bagisto.shop.products.view.after', static function (ViewRenderEventManager $manager) {
            if (($manager->getParam('product')?->type) === 'configurable' && request()->query('variant')) {
                $manager->addTemplate('google_merchant::shop.variant-preselect');
            }
        });
    }
}
