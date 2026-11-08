<?php

require_once __DIR__.'/../Helpers.php';

use Illuminate\Support\Facades\Event;

function renderPreselect($product, ?string $variant): string
{
    request()->query->replace($variant === null ? [] : ['variant' => $variant]);

    return view('google_merchant::shop.variant-preselect', ['product' => $product])->render();
}

it('emits the option steps for the requested variant', function () {
    $parent = configurableProduct(3);
    $variant = $parent->variants()->orderBy('id')->skip(1)->first();

    $html = renderPreselect($parent, $variant->sku);

    $colorId = \DB::table('attributes')->where('code', 'color')->value('id');
    $sizeId = \DB::table('attributes')->where('code', 'size')->value('id');

    expect($html)->toContain('gmc-variant-preselect')
        ->toContain('['.$colorId.','.optionId('color', 'Red').']')
        ->toContain('['.$sizeId.','.optionId('size', 'M').']');
});

it('renders nothing without a variant, for an unknown sku, or for simple products', function () {
    $parent = configurableProduct(2);

    expect(trim(renderPreselect($parent, null)))->toBe('')
        ->and(trim(renderPreselect($parent, 'no-such-sku')))->toBe('')
        ->and(trim(renderPreselect(simpleProduct(), 'x')))->toBe('');
});

it('escapes the requested sku', function () {
    $parent = configurableProduct(1);

    expect(renderPreselect($parent, '</script><script>alert(1)</script>'))->not->toContain('<script>alert(1)');
});

it('hooks into the shop product page only for variant links', function () {
    $product = configurableProduct(1);
    $templates = fn () => (new \Webkul\Theme\ViewRenderEventManager)
        ->handleRenderEvent('bagisto.shop.products.view.after', ['product' => $product])
        ->getTemplates();

    request()->query->replace([]);
    expect($templates())->not->toContain('google_merchant::shop.variant-preselect');

    request()->query->replace(['variant' => 'x']);
    expect($templates())->toContain('google_merchant::shop.variant-preselect');
});
