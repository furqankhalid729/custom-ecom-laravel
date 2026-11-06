<?php

use Custom\StorePolicy\Services\FooterLinkSync;
use Webkul\Theme\Models\ThemeCustomization;

function footerCustomization(): ?ThemeCustomization
{
    $channel = core()->getDefaultChannel();

    return ThemeCustomization::where('type', 'footer_links')
        ->where('channel_id', $channel->id)
        ->where('theme_code', $channel->theme)
        ->first();
}

function footerOptions(): array
{
    return footerCustomization()?->translate('en')?->options ?? [];
}

function footerLinkCount(array $options, string $urlKey): int
{
    $count = 0;

    foreach ($options as $column) {
        foreach ($column as $link) {
            if (str_ends_with((string) parse_url($link['url'], PHP_URL_PATH), '/page/'.$urlKey)) {
                $count++;
            }
        }
    }

    return $count;
}

it('does not duplicate seeded footer links', function () {
    $before = footerOptions();

    app(FooterLinkSync::class)->ensureLinks(core()->getDefaultChannel()->id, array_keys(config('store_policy.templates')));

    $after = footerOptions();

    expect(footerLinkCount($after, 'privacy-policy'))->toBe(1);

    foreach ($before as $column => $links) {
        expect(array_slice($after[$column], 0, count($links)))->toBe($links);
    }
});

it('adds a missing link to column_2', function () {
    $customization = footerCustomization();
    $translation = $customization->translate('en');
    $options = $translation->options;

    foreach ($options as $column => $links) {
        $options[$column] = array_values(array_filter(
            $links,
            fn ($link) => ! str_ends_with((string) parse_url($link['url'], PHP_URL_PATH), '/page/return-policy')
        ));
    }

    $translation->options = $options;
    $translation->save();

    $maxSort = max(array_column($options['column_2'], 'sort_order'));

    app(FooterLinkSync::class)->ensureLinks(core()->getDefaultChannel()->id, ['return-policy']);

    $added = collect(footerOptions()['column_2'])->last();

    expect($added['url'])->toEndWith('/page/return-policy');
    expect($added['title'])->toBe('Return Policy');
    expect($added['sort_order'])->toBe($maxSort + 1);
    expect(app(FooterLinkSync::class)->missingLinks(core()->getDefaultChannel()->id, ['return-policy']))->toBe([]);
});

it('creates footer customization when missing', function () {
    footerCustomization()->delete();

    app(FooterLinkSync::class)->ensureLinks(core()->getDefaultChannel()->id, ['privacy-policy', 'about-us']);

    $options = footerOptions();

    expect(footerLinkCount($options, 'privacy-policy'))->toBe(1);
    expect(footerLinkCount($options, 'about-us'))->toBe(1);
    expect(footerCustomization()->status)->toBe(1);
});
