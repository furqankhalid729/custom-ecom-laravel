<?php

require_once __DIR__.'/../Helpers.php';

use Custom\StorePolicy\Enums\PageStatus;
use Custom\StorePolicy\Models\PolicyGeneration;
use Custom\StorePolicy\Repositories\PolicySettingRepository;
use Custom\StorePolicy\Services\PolicyStatusService;
use Custom\StorePolicy\Services\SettingsHasher;
use Webkul\CMS\Models\PageTranslation;

function privacyPageTranslation(): PageTranslation
{
    return PageTranslation::where('url_key', 'privacy-policy')->where('locale', 'en')->firstOrFail();
}

function saveValidSettings(): array
{
    $settings = validSettings();

    app(PolicySettingRepository::class)->saveForChannel(core()->getDefaultChannel()->id, $settings);

    return $settings;
}

it('hash ignores unrelated settings', function () {
    $hasher = app(SettingsHasher::class);
    $settings = validSettings();

    $before = $hasher->hash($settings, ['regions.*.shipping']);

    $settings['privacy']['retention'] = 'Forever';
    expect($hasher->hash($settings, ['regions.*.shipping']))->toBe($before);

    $settings['regions']['US']['shipping']['handling_max_days'] = 9;
    expect($hasher->hash($settings, ['regions.*.shipping']))->not->toBe($before);
});

it('hash changes when a region is disabled', function () {
    $hasher = app(SettingsHasher::class);
    $settings = validSettings();

    $before = $hasher->hash($settings, ['regions.*.enabled']);

    $settings['regions']['US']['enabled'] = false;

    expect($hasher->hash($settings, ['regions.*.enabled']))->not->toBe($before);
});

it('reports not generated for seeded placeholder pages', function () {
    $statuses = app(PolicyStatusService::class)->forChannel(core()->getDefaultChannel()->id);

    expect($statuses)->toHaveCount(7);

    foreach ($statuses as $status) {
        expect($status['status'])->toBe(PageStatus::NotGenerated);
    }
});

it('reports edited when page html differs from generated hash', function () {
    $settings = saveValidSettings();
    $hasher = app(SettingsHasher::class);
    $translation = privacyPageTranslation();

    $translation->update(['html_content' => '<p>b</p>']);

    PolicyGeneration::create([
        'cms_page_id'         => $translation->cms_page_id,
        'locale'              => 'en',
        'template_key'        => 'privacy-policy',
        'generated_html_hash' => $hasher->htmlHash('<p>a</p>'),
        'settings_hash'       => $hasher->hash($settings, config('store_policy.templates.privacy-policy.depends')),
        'generated_at'        => now(),
    ]);

    $status = app(PolicyStatusService::class)->forChannel(core()->getDefaultChannel()->id)['privacy-policy'];

    expect($status['status'])->toBe(PageStatus::Edited);
    expect($status['page_id'])->toBe($translation->cms_page_id);
});

it('reports out_of_date when dependent settings changed', function () {
    saveValidSettings();
    $translation = privacyPageTranslation();

    PolicyGeneration::create([
        'cms_page_id'         => $translation->cms_page_id,
        'locale'              => 'en',
        'template_key'        => 'privacy-policy',
        'generated_html_hash' => app(SettingsHasher::class)->htmlHash($translation->html_content),
        'settings_hash'       => str_repeat('x', 40),
        'generated_at'        => now(),
    ]);

    $service = app(PolicyStatusService::class);

    expect($service->forChannel(core()->getDefaultChannel()->id)['privacy-policy']['status'])->toBe(PageStatus::OutOfDate);
    expect($service->outOfDateKeys(core()->getDefaultChannel()->id))->toBe(['privacy-policy']);
});

it('ignores whitespace-only differences in page html', function () {
    $hasher = app(SettingsHasher::class);

    expect($hasher->htmlHash("<p>a</p>\n  <p>b</p>"))->toBe($hasher->htmlHash('<p>a</p> <p>b</p>'));
});
