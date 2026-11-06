<?php

require_once __DIR__.'/../Helpers.php';

use Custom\StorePolicy\Repositories\PolicySettingRepository;
use Custom\StorePolicy\Services\PolicyGenerator;
use Custom\StorePolicy\Services\ReadinessChecker;
use Webkul\CMS\Models\PageTranslation;
use Webkul\Core\Models\CoreConfig;

function readiness(): array
{
    return collect(app(ReadinessChecker::class)->check(core()->getDefaultChannel()->id))->keyBy('key')->all();
}

function setConfig(string $code, string $value): void
{
    CoreConfig::query()->where('code', $code)->delete();
    CoreConfig::query()->create(['code' => $code, 'value' => $value]);
}

function generateEverything(): void
{
    app(PolicySettingRepository::class)->saveForChannel(core()->getDefaultChannel()->id, validSettings());

    app(PolicyGenerator::class)->generate(core()->getDefaultChannel()->id, array_keys(config('store_policy.templates')));
}

it('fails the essentials on a fresh install', function () {
    $checks = readiness();

    foreach (['business_contact', 'return_policy', 'shipping_policy', 'other_pages'] as $key) {
        expect($checks[$key]['passed'])->toBeFalse();
    }

    expect($checks['business_contact']['detail'])->toContain('business.email');
});

it('passes after settings saved and all pages generated', function () {
    setConfig('catalog.rich_snippets.products.enable', '1');
    setConfig('catalog.rich_snippets.products.show_offers', '1');

    generateEverything();

    $checks = readiness();

    foreach (['business_contact', 'return_policy', 'shipping_policy', 'other_pages', 'footer_links', 'structured_data'] as $key) {
        expect($checks[$key]['passed'])->toBeTrue("{$key} should pass");
    }
});

it('fails structured data when offers are hidden', function () {
    setConfig('catalog.rich_snippets.products.enable', '1');
    setConfig('catalog.rich_snippets.products.show_offers', '0');

    expect(readiness()['structured_data']['passed'])->toBeFalse();
});

it('flags other locales with placeholder text', function () {
    generateEverything();

    $en = PageTranslation::where('url_key', 'privacy-policy')->where('locale', 'en')->first();

    PageTranslation::where('cms_page_id', $en->cms_page_id)->where('locale', 'ar')->delete();

    PageTranslation::create([
        'cms_page_id'  => $en->cms_page_id,
        'locale'       => 'ar',
        'url_key'      => 'privacy-policy',
        'page_title'   => 'Privacy Policy',
        'html_content' => '<p>placeholder</p>',
    ]);

    $check = readiness()['other_locales'];

    expect($check['passed'])->toBeFalse();
    expect($check['detail'])->toContain('ar');
});
