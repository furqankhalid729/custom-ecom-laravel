<?php

use Custom\ReviewImport\Http\Controllers\Admin\ReviewImportController;
use Illuminate\Support\Facades\Route;

Route::controller(ReviewImportController::class)->prefix('customers/reviews/import')->group(function () {
    Route::get('/', 'index')->name('admin.customers.reviews.import.index');

    Route::get('template', 'template')->name('admin.customers.reviews.import.template');

    Route::post('preview', 'preview')->name('admin.customers.reviews.import.preview');

    Route::post('/', 'import')->name('admin.customers.reviews.import.store');
});
