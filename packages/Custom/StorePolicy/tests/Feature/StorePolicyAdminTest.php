<?php

require_once __DIR__.'/../Helpers.php';

use Custom\StorePolicy\Repositories\PolicySettingRepository;
use Custom\StorePolicy\Services\PolicyGenerator;
use Webkul\CMS\Models\PageTranslation;

use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

/**
 * Settings as the admin form submits them: strings, "0"/"1" booleans, textarea lists.
 */
function formPayload(?callable $modify = null): array
{
    $data = validSettings();

    $data['payment']['methods'] = implode("\n", $data['payment']['methods']);
    $data['privacy']['data_collected'] = implode("\n", $data['privacy']['data_collected']);
    $data['privacy']['third_parties'] = implode("\n", $data['privacy']['third_parties']);
    $data['privacy']['uses_cookies'] = '1';

    foreach ($data['regions'] as $code => $region) {
        $data['regions'][$code]['enabled'] = '1';
        $data['regions'][$code]['returns']['accepted'] = '1';
        $data['regions'][$code]['returns']['window_days'] = (string) $region['returns']['window_days'];
        $data['regions'][$code]['shipping']['methods'][] = ['name' => '', 'carrier' => '', 'cost' => '', 'free_over' => '', 'min_days' => '', 'max_days' => ''];
    }

    return $modify ? $modify($data) : $data;
}

it('shows the store policies screen', function () {
    $this->loginAsAdmin();

    $response = get(route('admin.cms.store_policies.index'))->assertOk();

    $response->assertSeeText(trans('store_policy::app.index.title'));
    $response->assertSee('name="privacy[sells_or_shares]"', false);

    preg_match('#id="sp-regions-EU-returns-refund_method".*?</select>#s', $response->getContent(), $euRefund);
    expect($euRefund[0] ?? '')->toContain('value="original"')->not->toContain('value="store_credit"');

    preg_match('#id="sp-regions-US-returns-refund_method".*?</select>#s', $response->getContent(), $usRefund);
    expect($usRefund[0] ?? '')->toContain('value="store_credit"');

    foreach (config('store_policy.templates') as $template) {
        $response->assertSeeText($template['title']);
    }
});

it('saves settings submitted by the form', function () {
    $this->loginAsAdmin();

    put(route('admin.cms.store_policies.update'), formPayload())
        ->assertRedirect(route('admin.cms.store_policies.index'))
        ->assertSessionHasNoErrors();

    $settings = app(PolicySettingRepository::class)->forChannel(core()->getDefaultChannel()->id);

    expect($settings->business['legal_name'])->toBe('Acme Trading Ltd');
    expect($settings->regions['EU']['returns']['window_days'])->toBe(14);
    expect($settings->regions['EU']['returns']['accepted'])->toBeTrue();
    expect($settings->regions['EU']['shipping']['methods'])->toHaveCount(1);
    expect($settings->regions['EU']['shipping']['methods'][0]['cost'])->toBe(4.95);
    expect($settings->payment['methods'])->toBe(validSettings()['payment']['methods']);
});

it('keeps a disabled region settings when saving', function () {
    $this->loginAsAdmin();

    put(route('admin.cms.store_policies.update'), formPayload(function ($data) {
        $data['regions']['US']['enabled'] = '0';

        return $data;
    }))->assertSessionHasNoErrors();

    $settings = app(PolicySettingRepository::class)->forChannel(core()->getDefaultChannel()->id);

    expect($settings->regions['US']['enabled'])->toBeFalse();
    expect($settings->regions['US']['shipping']['methods'][0]['name'])->toBe('US Standard');
});

it('saves settings and flags out of date pages', function () {
    $this->loginAsAdmin();

    app(PolicySettingRepository::class)->saveForChannel(core()->getDefaultChannel()->id, validSettings());
    app(PolicyGenerator::class)->generate(core()->getDefaultChannel()->id, array_keys(config('store_policy.templates')));

    put(route('admin.cms.store_policies.update'), formPayload(function ($data) {
        $data['regions']['EU']['shipping']['handling_max_days'] = '6';
        $data['terms']['effective_date'] = app(PolicySettingRepository::class)->forChannel(core()->getDefaultChannel()->id)->terms['effective_date'];

        return $data;
    }))->assertSessionHasNoErrors();

    $outOfDate = session('store_policy_out_of_date');

    expect($outOfDate)->toContain('shipping-policy');
    expect($outOfDate)->not->toContain('about-us');
});

it('rejects EU window under 14 via http', function () {
    $this->loginAsAdmin();

    put(route('admin.cms.store_policies.update'), formPayload(function ($data) {
        $data['regions']['EU']['returns']['window_days'] = '13';

        return $data;
    }))->assertSessionHasErrors('regions.EU.returns.window_days');
});

it('generates selected pages via http', function () {
    $this->loginAsAdmin();

    app(PolicySettingRepository::class)->saveForChannel(core()->getDefaultChannel()->id, validSettings());

    post(route('admin.cms.store_policies.generate'), ['templates' => ['privacy-policy']])
        ->assertRedirect(route('admin.cms.store_policies.index'))
        ->assertSessionHas('success');

    expect(PageTranslation::where('url_key', 'privacy-policy')->where('locale', 'en')->value('html_content'))
        ->toContain('Acme Trading Ltd');
});

it('reports missing settings on generate', function () {
    $this->loginAsAdmin();

    post(route('admin.cms.store_policies.generate'), ['templates' => ['privacy-policy']])
        ->assertRedirect(route('admin.cms.store_policies.index'))
        ->assertSessionHas('error');
});

it('restores via http', function () {
    $this->loginAsAdmin();

    app(PolicySettingRepository::class)->saveForChannel(core()->getDefaultChannel()->id, validSettings());

    $original = PageTranslation::where('url_key', 'privacy-policy')->where('locale', 'en')->value('html_content');

    app(PolicyGenerator::class)->generate(core()->getDefaultChannel()->id, ['privacy-policy']);

    post(route('admin.cms.store_policies.restore', 'privacy-policy'))
        ->assertRedirect(route('admin.cms.store_policies.index'))
        ->assertSessionHas('success');

    expect(PageTranslation::where('url_key', 'privacy-policy')->where('locale', 'en')->value('html_content'))->toBe($original);
});

it('adds store policies to the admin menu', function () {
    $this->loginAsAdmin();

    get(route('admin.cms.index'))
        ->assertOk()
        ->assertSeeText(trans('store_policy::app.menu.store-policies'));
});
