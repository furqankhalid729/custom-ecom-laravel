<?php

require_once __DIR__.'/../Helpers.php';

use Custom\StorePolicy\Services\PolicyRenderer;

function renderPolicy(string $key, array $settings): string
{
    return app(PolicyRenderer::class)->render($key, $settings);
}

it('renders return policy with EU and US sections', function () {
    $settings = validSettings();
    $settings['regions']['US']['returns']['window_days'] = 45;

    $html = renderPolicy('return-policy', $settings);

    expect($html)
        ->toContain('Customers in the European Union')
        ->toContain('Customers in the United States')
        ->toContain('14 days')
        ->toContain('45 days')
        ->toContain('support@acme.test')
        ->toContain('withdrawal');
});

it('omits a disabled region', function () {
    $settings = validSettings();
    $settings['regions']['US']['enabled'] = false;

    expect(renderPolicy('return-policy', $settings))->not->toContain('Customers in the United States');
});

it('renders shipping methods with region currency', function () {
    $html = renderPolicy('shipping-policy', validSettings());

    expect($html)
        ->toContain('EU Standard')
        ->toContain('€4.95')
        ->toContain('US Standard')
        ->toContain('$7.50')
        ->toContain('all EU member states');
});

it('renders all sales final when US returns not accepted', function () {
    $settings = validSettings();
    $settings['regions']['US']['returns']['accepted'] = false;

    expect(renderPolicy('return-policy', $settings))->toContain('All sales are final');
});

it('includes California rights only when US enabled', function () {
    $settings = validSettings();

    expect(renderPolicy('privacy-policy', $settings))->toContain('California');

    $settings['regions']['US']['enabled'] = false;

    expect(renderPolicy('privacy-policy', $settings))->not->toContain('California');
});

it('renders every configured template', function () {
    $settings = validSettings();

    foreach (array_keys(config('store_policy.templates')) as $key) {
        $html = renderPolicy($key, $settings);

        expect($html)->not->toBeEmpty()->toContain('Acme Trading Ltd');
        expect($html)->not->toContain('<div')->not->toContain('<table');
    }
});

it('survives the CMS content cleaner unchanged', function () {
    $html = renderPolicy('return-policy', validSettings());

    expect(clean_content($html))->toBe($html);
});

it('does not shorten the EU legal guarantee with a reporting deadline', function () {
    $settings = validSettings();
    $settings['regions']['US']['enabled'] = false;
    $settings['regions']['EU']['returns']['defect_report_days'] = 30;
    $settings['regions']['EU']['returns']['non_returnable'] = 'custom engraved items';

    $html = renderPolicy('return-policy', $settings);

    expect($html)->not->toContain('within 30 days')
        ->not->toContain('In addition:')
        ->toContain('custom engraved items');
});

it('states data is not sold only when configured so', function () {
    $settings = validSettings();
    $settings['privacy']['sells_or_shares'] = false;

    expect(renderPolicy('privacy-policy', $settings))->toContain('We do not sell your personal information');

    $settings['privacy']['sells_or_shares'] = true;

    $html = renderPolicy('privacy-policy', $settings);

    expect($html)->not->toContain('We do not sell')->toContain('opt out');
});

it('renders terms currency sentence without stray punctuation', function () {
    $html = renderPolicy('terms-conditions', validSettings());

    expect($html)->not->toContain(' .')->toContain('United States in USD.');
});

it('does not claim US prices include tax', function () {
    expect(renderPolicy('payment-policy', validSettings()))->not->toContain('Prices include any applicable taxes');
});

it('shows the generation date as last updated', function () {
    $settings = validSettings();
    $settings['terms']['effective_date'] = '2026-01-15';

    $html = renderPolicy('shipping-policy', $settings);

    expect($html)->toContain('Last updated: '.now()->format('F j, Y'));
    expect($html)->toContain('Effective date: January 15, 2026');
});

it('throws for unknown template', function () {
    renderPolicy('no-such-policy', validSettings());
})->throws(InvalidArgumentException::class);
