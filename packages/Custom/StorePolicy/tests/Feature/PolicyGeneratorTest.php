<?php

require_once __DIR__.'/../Helpers.php';

use Custom\StorePolicy\Enums\PageStatus;
use Custom\StorePolicy\Exceptions\MissingSettingsException;
use Custom\StorePolicy\Models\PolicyGeneration;
use Custom\StorePolicy\Repositories\PolicySettingRepository;
use Custom\StorePolicy\Services\PolicyGenerator;
use Custom\StorePolicy\Services\PolicyStatusService;
use Webkul\CMS\Models\Page;
use Webkul\CMS\Models\PageTranslation;

use function Pest\Laravel\putJson;

function channelId(): int
{
    return core()->getDefaultChannel()->id;
}

function storeValidSettings(): array
{
    $settings = validSettings();

    app(PolicySettingRepository::class)->saveForChannel(channelId(), $settings);

    return $settings;
}

function pageHtml(string $urlKey): ?string
{
    return PageTranslation::where('url_key', $urlKey)->where('locale', 'en')->value('html_content');
}

function setPageHtml(string $urlKey, string $html): void
{
    PageTranslation::where('url_key', $urlKey)->where('locale', 'en')->update(['html_content' => $html]);
}

function statusOf(string $key): PageStatus
{
    return app(PolicyStatusService::class)->forChannel(channelId())[$key]['status'];
}

it('refuses to generate when required settings are missing', function () {
    $before = pageHtml('privacy-policy');

    try {
        app(PolicyGenerator::class)->generate(channelId(), ['privacy-policy']);

        $this->fail('Expected MissingSettingsException');
    } catch (MissingSettingsException $e) {
        expect($e->missing)->toContain('business.email');
    }

    expect(pageHtml('privacy-policy'))->toBe($before);
});

it('generates selected pages and marks them up to date', function () {
    storeValidSettings();

    $result = app(PolicyGenerator::class)->generate(channelId(), ['return-policy', 'shipping-policy']);

    expect($result['generated'])->toBe(['return-policy', 'shipping-policy']);
    expect(pageHtml('return-policy'))->toContain('Customers in the European Union');
    expect(statusOf('return-policy'))->toBe(PageStatus::UpToDate);
    expect(statusOf('shipping-policy'))->toBe(PageStatus::UpToDate);
    expect(statusOf('privacy-policy'))->toBe(PageStatus::NotGenerated);
});

it('sets the effective date on first generation', function () {
    storeValidSettings();

    app(PolicyGenerator::class)->generate(channelId(), ['terms-conditions']);

    $settings = app(PolicySettingRepository::class)->forChannel(channelId());

    expect($settings->terms['effective_date'])->toBe(now()->format('Y-m-d'));
    expect(statusOf('terms-conditions'))->toBe(PageStatus::UpToDate);
});

it('creates a missing page assigned to the channel', function () {
    storeValidSettings();

    Page::whereHas('translations', fn ($q) => $q->where('url_key', 'about-us'))->get()->each->delete();

    app(PolicyGenerator::class)->generate(channelId(), ['about-us']);

    $page = Page::whereHas('translations', fn ($q) => $q->where('url_key', 'about-us'))->first();

    expect($page)->not->toBeNull();
    expect($page->channels->pluck('id')->all())->toContain(channelId());
    expect(pageHtml('about-us'))->toContain('Acme Trading Ltd');
});

it('skips hand-edited pages without confirmation', function () {
    storeValidSettings();
    $generator = app(PolicyGenerator::class);

    $generator->generate(channelId(), ['privacy-policy']);
    setPageHtml('privacy-policy', '<p>My own privacy text</p>');

    $result = $generator->generate(channelId(), ['privacy-policy']);

    expect($result['skipped'])->toBe(['privacy-policy']);
    expect(pageHtml('privacy-policy'))->toBe('<p>My own privacy text</p>');
});

it('overwrites hand-edited pages when confirmed and keeps previous html', function () {
    storeValidSettings();
    $generator = app(PolicyGenerator::class);

    $generator->generate(channelId(), ['privacy-policy']);
    setPageHtml('privacy-policy', '<p>My own privacy text</p>');

    $result = $generator->generate(channelId(), ['privacy-policy'], ['privacy-policy']);

    expect($result['generated'])->toBe(['privacy-policy']);
    expect(pageHtml('privacy-policy'))->toContain('Acme Trading Ltd');
    expect(PolicyGeneration::where('template_key', 'privacy-policy')->value('previous_html'))->toBe('<p>My own privacy text</p>');
});

it('restores previous version', function () {
    storeValidSettings();
    $generator = app(PolicyGenerator::class);

    $generator->generate(channelId(), ['privacy-policy']);
    setPageHtml('privacy-policy', '<p>My own privacy text</p>');
    $generator->generate(channelId(), ['privacy-policy'], ['privacy-policy']);

    $generator->restore(channelId(), 'privacy-policy');

    expect(pageHtml('privacy-policy'))->toBe('<p>My own privacy text</p>');
    expect(statusOf('privacy-policy'))->toBe(PageStatus::Edited);
});

it('has nothing to restore before any page was replaced', function () {
    storeValidSettings();

    Page::whereHas('translations', fn ($q) => $q->where('url_key', 'about-us'))->get()->each->delete();

    app(PolicyGenerator::class)->generate(channelId(), ['about-us']);

    expect(fn () => app(PolicyGenerator::class)->restore(channelId(), 'about-us'))->toThrow(RuntimeException::class);
});

it('stays up to date after an unchanged CMS editor save', function () {
    storeValidSettings();
    $this->loginAsAdmin();

    app(PolicyGenerator::class)->generate(channelId(), ['privacy-policy']);

    $translation = PageTranslation::where('url_key', 'privacy-policy')->where('locale', 'en')->first();

    putJson(route('admin.cms.update', $translation->cms_page_id), [
        'en' => [
            'url_key'          => $translation->url_key,
            'page_title'       => $translation->page_title,
            'html_content'     => $translation->html_content,
            'meta_title'       => $translation->meta_title,
            'meta_description' => $translation->meta_description,
            'meta_keywords'    => $translation->meta_keywords,
        ],
        'channels' => [channelId()],
        'locale'   => 'en',
    ])->assertRedirect();

    expect(statusOf('privacy-policy'))->toBe(PageStatus::UpToDate);
});

it('regenerates after page deleted', function () {
    storeValidSettings();
    $generator = app(PolicyGenerator::class);

    $generator->generate(channelId(), ['refund-policy']);

    Page::whereHas('translations', fn ($q) => $q->where('url_key', 'refund-policy'))->get()->each->delete();

    expect(statusOf('refund-policy'))->toBe(PageStatus::NotGenerated);

    $result = $generator->generate(channelId(), ['refund-policy']);

    expect($result['generated'])->toBe(['refund-policy']);
    expect(statusOf('refund-policy'))->toBe(PageStatus::UpToDate);
});

it('becomes out of date after a dependent setting changes', function () {
    $settings = storeValidSettings();

    app(PolicyGenerator::class)->generate(channelId(), ['shipping-policy', 'about-us']);

    $settings['regions']['EU']['shipping']['handling_max_days'] = 5;
    app(PolicySettingRepository::class)->saveForChannel(channelId(), $settings);

    expect(statusOf('shipping-policy'))->toBe(PageStatus::OutOfDate);
    expect(statusOf('about-us'))->toBe(PageStatus::UpToDate);
});

it('stays up to date after a TinyMCE-style round trip', function () {
    storeValidSettings();

    app(PolicyGenerator::class)->generate(channelId(), ['terms-conditions']);

    $html = pageHtml('terms-conditions');

    // TinyMCE puts newlines between block elements and makes own-site links absolute.
    $tiny = preg_replace('#(</(?:p|h1|h2|h3|ul|li)>)<#', "\$1\n<", $html);
    $tiny = str_replace('href="/page/', 'href="'.rtrim(config('app.url'), '/').'/page/', $tiny);

    expect($tiny)->not->toBe($html);

    setPageHtml('terms-conditions', $tiny);

    expect(statusOf('terms-conditions'))->toBe(PageStatus::UpToDate);
});

it('keeps the hand-written version when regenerating again later', function () {
    $settings = storeValidSettings();
    $generator = app(PolicyGenerator::class);

    setPageHtml('about-us', '<p>Our own story</p>');
    $generator->generate(channelId(), ['about-us']);

    $settings['business']['phone'] = '+353 1 999 9999';
    app(PolicySettingRepository::class)->saveForChannel(channelId(), $settings);
    $generator->generate(channelId(), ['about-us']);

    $generator->restore(channelId(), 'about-us');

    expect(pageHtml('about-us'))->toBe('<p>Our own story</p>');
});

it('can undo a restore', function () {
    storeValidSettings();
    $generator = app(PolicyGenerator::class);

    setPageHtml('privacy-policy', '<p>Mine</p>');
    $generator->generate(channelId(), ['privacy-policy']);
    $generated = pageHtml('privacy-policy');

    $generator->restore(channelId(), 'privacy-policy');
    expect(pageHtml('privacy-policy'))->toBe('<p>Mine</p>');

    $generator->restore(channelId(), 'privacy-policy');
    expect(pageHtml('privacy-policy'))->toBe($generated);
    expect(statusOf('privacy-policy'))->toBe(PageStatus::UpToDate);
});

it('marks about us out of date when a region is disabled', function () {
    $settings = storeValidSettings();

    app(PolicyGenerator::class)->generate(channelId(), ['about-us']);

    $settings['regions']['US']['enabled'] = false;
    app(PolicySettingRepository::class)->saveForChannel(channelId(), $settings);

    expect(statusOf('about-us'))->toBe(PageStatus::OutOfDate);
});
