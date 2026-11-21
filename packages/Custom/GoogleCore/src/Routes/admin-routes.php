<?php

use Custom\GoogleCore\Http\Controllers\IntegrationsController;
use Illuminate\Support\Facades\Route;

Route::get('google', [IntegrationsController::class, 'index'])->name('admin.google.integrations');
