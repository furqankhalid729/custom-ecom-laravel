<?php

require_once __DIR__.'/../../../GoogleMerchant/tests/Helpers.php';

use Custom\GoogleCore\Contracts\GoogleIntegration;
use Custom\GoogleCore\Integrations\IntegrationRegistry;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

use function Pest\Laravel\get;

it('lists merchant center as connected once an account is connected', function () {
    $this->loginAsAdmin();

    get(route('admin.google.integrations'))
        ->assertOk()
        ->assertSeeText('Merchant Center')
        ->assertSeeText(trans('google_core::app.integrations.not-connected'))
        ->assertDontSeeText(trans('google_core::app.integrations.connected'));

    gmcAccount();

    get(route('admin.google.integrations'))
        ->assertOk()
        ->assertSeeText(trans('google_core::app.integrations.connected'))
        ->assertSee(route('admin.google_merchant.overview'), false);
});

it('shows any integration a sibling package registers', function () {
    app(IntegrationRegistry::class)->register(new class implements GoogleIntegration
    {
        public function key(): string
        {
            return 'ga4';
        }

        public function label(): string
        {
            return 'Analytics 4 (test)';
        }

        public function isConnected(): bool
        {
            return false;
        }

        public function adminRoute(): ?string
        {
            return null;
        }
    });

    $this->loginAsAdmin();

    get(route('admin.google.integrations'))->assertOk()->assertSeeText('Analytics 4 (test)');
});

it('is protected by its own permission', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['marketing.google_merchant.overview']]);
    $this->loginAsAdmin(Admin::factory()->create(['role_id' => $role->id]));

    get(route('admin.google.integrations'))->assertStatus(401);
});

it('sits above merchant center in the marketing menu', function () {
    $sort = collect(config('menu.admin'))->pluck('sort', 'key');

    expect($sort->has('marketing.google'))->toBeTrue()
        ->and($sort['marketing.google'])->toBeLessThan($sort['marketing.google_merchant']);
});
