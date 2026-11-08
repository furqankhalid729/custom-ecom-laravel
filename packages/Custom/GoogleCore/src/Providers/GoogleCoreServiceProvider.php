<?php

namespace Custom\GoogleCore\Providers;

use Custom\GoogleCore\Integrations\IntegrationRegistry;
use Illuminate\Support\ServiceProvider;

class GoogleCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(IntegrationRegistry::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'google_core');

        $this->app->register(ModuleServiceProvider::class);
    }
}
