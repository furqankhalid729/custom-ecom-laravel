<?php

namespace Custom\GoogleCore\Http\Controllers;

use Custom\GoogleCore\Contracts\GoogleIntegration;
use Custom\GoogleCore\Integrations\IntegrationRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;

/**
 * Marketing → Google: one card per Google service package that registered itself.
 */
class IntegrationsController extends Controller
{
    public function index(IntegrationRegistry $registry): View
    {
        $integrations = collect($registry->all())->map(fn (GoogleIntegration $integration) => [
            'key' => $integration->key(),
            'label' => $integration->label(),
            'connected' => $integration->isConnected(),
            'url' => ($route = $integration->adminRoute()) && Route::has($route) ? route($route) : null,
        ])->values();

        return view('google_core::admin.integrations', ['integrations' => $integrations]);
    }
}
