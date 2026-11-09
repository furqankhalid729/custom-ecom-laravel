<?php

use Custom\GoogleMerchant\Http\Controllers\Admin\ConnectionController;
use Custom\GoogleMerchant\Http\Controllers\Admin\LogController;
use Custom\GoogleMerchant\Http\Controllers\Admin\MappingController;
use Custom\GoogleMerchant\Http\Controllers\Admin\OverviewController;
use Custom\GoogleMerchant\Http\Controllers\Admin\ProductSyncController;
use Custom\GoogleMerchant\Http\Controllers\Admin\RunController;
use Custom\GoogleMerchant\Http\Controllers\Admin\StoreSettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('google-merchant')->name('admin.google_merchant.')->group(function () {
    Route::get('/', [OverviewController::class, 'index'])->name('overview');

    Route::controller(ConnectionController::class)->group(function () {
        Route::get('connection', 'index')->name('connection.index');
        Route::post('connection/oauth', 'startOAuth')->name('oauth.start');
        Route::get('oauth/callback', 'callback')->name('oauth.callback');
        Route::post('connection/service-account', 'serviceAccount')->name('service_account');
        Route::post('connection/merchant', 'selectMerchant')->name('merchant.select');
        Route::delete('connection', 'disconnect')->name('disconnect');
    });

    Route::controller(ProductSyncController::class)->group(function () {
        Route::get('products', 'index')->name('products.index');
        Route::post('products/mass-sync', 'massSync')->name('products.mass_sync');
        Route::post('products/mass-retry', 'massRetry')->name('products.mass_retry');
        Route::post('products/mass-exclude', 'massExclude')->name('products.mass_exclude');
        Route::post('products/{gmcProduct}/sync', 'sync')->name('products.sync');
        Route::post('products/{gmcProduct}/retry', 'retry')->name('products.retry');
        Route::post('products/{gmcProduct}/exclude', 'exclude')->name('products.exclude');
        Route::post('products/{gmcProduct}/include', 'include')->name('products.include');
        Route::delete('products/{gmcProduct}/google', 'remove')->name('products.remove');
        Route::post('product/{product}/sync', 'syncStoreProduct')->name('product.sync');
    });

    Route::controller(MappingController::class)->prefix('mapping')->name('mapping.')->group(function () {
        Route::get('attributes', 'attributes')->name('attributes');
        Route::put('attributes', 'updateAttributes')->name('attributes.update');
        Route::get('categories', 'categories')->name('categories');
        Route::put('categories', 'updateCategories')->name('categories.update');
        Route::get('taxonomy', 'taxonomy')->name('taxonomy');
    });

    Route::controller(StoreSettingsController::class)->group(function () {
        Route::get('store', 'edit')->name('store.edit');
        Route::put('store', 'update')->name('store.update');
    });

    Route::controller(LogController::class)->group(function () {
        Route::get('logs', 'index')->name('logs.index');
        Route::get('logs/{log}', 'show')->whereNumber('log')->name('logs.show');
    });

    Route::controller(RunController::class)->group(function () {
        Route::get('runs', 'index')->name('runs.index');
        Route::post('runs', 'store')->name('runs.store');
        Route::post('runs/delete-all', 'deleteAll')->name('runs.delete_all');
        Route::get('runs/{run}', 'show')->name('runs.show');
        Route::post('runs/{run}/pause', 'pause')->name('runs.pause');
        Route::post('runs/{run}/resume', 'resume')->name('runs.resume');
    });
});
