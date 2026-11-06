<?php

use Custom\StorePolicy\Http\Controllers\Admin\StorePolicyController;
use Illuminate\Support\Facades\Route;

Route::controller(StorePolicyController::class)->prefix('cms/store-policies')->group(function () {
    Route::get('/', 'index')->name('admin.cms.store_policies.index');

    Route::put('/', 'update')->name('admin.cms.store_policies.update');

    Route::post('generate', 'generate')->name('admin.cms.store_policies.generate');

    Route::post('{policy}/restore', 'restore')
        ->where('policy', '[a-z0-9-]+')
        ->name('admin.cms.store_policies.restore');
});
