<?php

require_once __DIR__.'/../Helpers.php';

use Custom\StorePolicy\Repositories\PolicySettingRepository;
use Webkul\Faker\Helpers\Product as ProductFaker;
use Webkul\Product\Helpers\SEO;

use function Pest\Laravel\get;

beforeEach(function () {
    // The full-page cache (file store) would serve pages cached by earlier runs.
    config(['responsecache.enabled' => false]);
});

function saveStructuredDataSettings(?callable $modify = null): array
{
    $settings = validSettings();

    if ($modify) {
        $settings = $modify($settings);
    }

    app(PolicySettingRepository::class)->saveForChannel(core()->getDefaultChannel()->id, $settings);

    return $settings;
}

function makeProduct()
{
    return (new ProductFaker)->getSimpleProductFactory()->create();
}

it('adds shipping details and return policies to product offers', function () {
    saveStructuredDataSettings();

    $offers = app(SEO::class)->getProductOffers(makeProduct());

    expect($offers['@type'])->toBe('Offer');
    expect($offers['hasMerchantReturnPolicy'])->toHaveCount(2);

    $eu = collect($offers['hasMerchantReturnPolicy'])->first(fn ($p) => count($p['applicableCountry']) === 27);

    expect($eu['merchantReturnDays'])->toBe(14);
    expect($eu['returnPolicyCategory'])->toBe('https://schema.org/MerchantReturnFiniteReturnWindow');

    $currencies = array_map(fn ($d) => $d['shippingRate']['currency'], $offers['shippingDetails']);
    expect($currencies)->toEqualCanonicalizing(['EUR', 'USD']);

    $us = collect($offers['shippingDetails'])->first(fn ($d) => $d['shippingRate']['currency'] === 'USD');
    expect($us['shippingRate']['value'])->toBe(7.5);
    expect($us['deliveryTime']['transitTime']['maxValue'])->toBe(10);
    expect($us['shippingDestination'][0]['addressCountry'])->toBe('US');
});

it('leaves offers untouched without saved settings', function () {
    $offers = app(SEO::class)->getProductOffers(makeProduct());

    expect(array_keys($offers))->toBe(['@type', 'priceCurrency', 'price', 'availability']);
});

it('drops disabled region from structured data', function () {
    saveStructuredDataSettings(function ($settings) {
        $settings['regions']['US']['enabled'] = false;

        return $settings;
    });

    $offers = app(SEO::class)->getProductOffers(makeProduct());

    expect($offers['hasMerchantReturnPolicy'])->toHaveCount(1);

    foreach ($offers['shippingDetails'] as $details) {
        expect($details['shippingRate']['currency'])->toBe('EUR');
    }
});

it('marks returns not permitted when a region does not accept returns', function () {
    saveStructuredDataSettings(function ($settings) {
        $settings['regions']['US']['returns']['accepted'] = false;

        return $settings;
    });

    $offers = app(SEO::class)->getProductOffers(makeProduct());

    $us = collect($offers['hasMerchantReturnPolicy'])->first(fn ($p) => $p['applicableCountry'] === ['US']);

    expect($us['returnPolicyCategory'])->toBe('https://schema.org/MerchantReturnNotPermitted');
    expect($us)->not->toHaveKey('merchantReturnDays');
});

it('emits zero shipping cost when the product price reaches the free shipping threshold', function () {
    $product = makeProduct();
    $offers = app(SEO::class)->getProductOffers($product);
    $currency = $offers['priceCurrency'];
    $code = $currency === 'EUR' ? 'EU' : 'US';

    saveStructuredDataSettings(function ($settings) use ($offers, $currency, $code) {
        $settings['regions'][$code]['currency'] = $currency;
        $settings['regions'][$code]['shipping']['methods'][0]['free_over'] = max(0, (float) $offers['price'] - 1);

        return $settings;
    });

    $details = app(SEO::class)->getProductOffers($product)['shippingDetails'];

    $rate = collect($details)->first(fn ($d) => $d['shippingRate']['currency'] === $currency)['shippingRate'];

    expect($rate['value'])->toBe(0.0);
});

it('does not claim free returns when a restocking fee applies', function () {
    saveStructuredDataSettings(function ($settings) {
        $settings['regions']['US']['returns']['return_shipping_paid_by'] = 'store';
        $settings['regions']['US']['returns']['restocking_fee_percent'] = 15;

        return $settings;
    });

    $offers = app(SEO::class)->getProductOffers(makeProduct());

    $us = collect($offers['hasMerchantReturnPolicy'])->first(fn ($p) => $p['applicableCountry'] === ['US']);

    expect($us['returnFees'])->not->toBe('https://schema.org/FreeReturn');
});

it('renders organization json-ld on the storefront', function () {
    saveStructuredDataSettings();

    get(route('shop.home.index'))
        ->assertOk()
        ->assertSee('"@type":"Organization"', false)
        ->assertSee('Acme Trading Ltd', false);
});

it('does not render organization json-ld without saved settings', function () {
    get(route('shop.home.index'))
        ->assertOk()
        ->assertDontSee('"@type":"Organization"', false);
});

it('styles policy page headings and lists on cms pages', function () {
    get(route('shop.cms.page', 'privacy-policy'))
        ->assertOk()
        ->assertSee('id="store-policy-page-styles"', false);

    get(route('shop.home.index'))
        ->assertOk()
        ->assertDontSee('id="store-policy-page-styles"', false);
});
