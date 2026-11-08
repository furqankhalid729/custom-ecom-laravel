<?php

require_once __DIR__.'/../Helpers.php';

use Custom\GoogleMerchant\Mapping\MappedOffer;
use Custom\GoogleMerchant\Mapping\ProductMapper;
use Illuminate\Support\Facades\DB;
use Webkul\Faker\Helpers\Product as ProductFaker;

beforeEach(function () {
    gmcConfig(['store.website_url' => 'https://shop.example.com']);

    $this->target = gmcTarget(gmcAccount());
    $this->map = fn ($product) => app(ProductMapper::class)->map($product, $this->target);
});

it('maps a simple product', function () {
    $product = simpleProduct();

    $offers = ($this->map)($product);

    expect($offers)->toHaveCount(1)->and($offers[0])->toBeInstanceOf(MappedOffer::class);

    $input = $offers[0]->productInput;
    $a = $input['productAttributes'];

    expect($input['offerId'])->toBe($product->sku)
        ->and($input['contentLanguage'])->toBe('en')
        ->and($input['feedLabel'])->toBe('US')
        ->and($a['title'])->toBe('Arctic Cozy Knit Beanie')
        ->and($a['description'])->toBe('Warm knit beanie & soft lining.')
        ->and($a['link'])->toBe('https://shop.example.com/arctic-cozy-beanie-'.$product->id)
        ->and($a['imageLink'])->toBe("https://shop.example.com/storage/product/{$product->id}/main.webp")
        ->and($a['price'])->toBe(['amountMicros' => '21000000', 'currencyCode' => 'USD'])
        ->and($a['availability'])->toBe('IN_STOCK')
        ->and($a['condition'])->toBe('NEW')
        ->and($a['brand'])->toBe('Arctic')
        ->and($a['gtins'])->toBe(['4006381333931'])
        ->and($a['mpn'])->toBe('MPN-'.$product->id)
        ->and($a)->not->toHaveKey('identifierExists')
        ->and($a)->not->toHaveKey('salePrice')
        ->and($a)->not->toHaveKey('itemGroupId')
        ->and($offers[0]->productId)->toBe($product->id)
        ->and($offers[0]->rootProductId)->toBe($product->id);
});

it('maps a configurable product to one offer per variant', function () {
    $parent = configurableProduct(3);

    $offers = ($this->map)($parent);

    expect($offers)->toHaveCount(3);

    $variants = $parent->variants()->orderBy('id')->get();

    foreach ($offers as $i => $offer) {
        $a = $offer->productInput['productAttributes'];

        expect($offer->offerId)->toBe($variants[$i]->sku)
            ->and($offer->itemGroupId)->toBe($parent->sku)
            ->and($offer->rootProductId)->toBe($parent->id)
            ->and($offer->productId)->toBe($variants[$i]->id)
            ->and($a['itemGroupId'])->toBe($parent->sku)
            ->and($a['link'])->toBe('https://shop.example.com/puffer-jacket-'.$parent->id.'?variant='.rawurlencode($variants[$i]->sku))
            ->and($a['color'])->toBe('Red')
            ->and($a['size'])->toBe(['S', 'M', 'L'][$i])
            ->and($a['price']['amountMicros'])->toBe([ '14000000', '17000000', '21000000'][$i]);
    }
});

it('falls back to parent fields for variants', function () {
    $parent = configurableProduct(1);

    $a = ($this->map)($parent)[0]->productInput['productAttributes'];

    expect($a['title'])->toBe('Puffer Jacket – Red / S')
        ->and($a['description'])->toBe('Warm puffer jacket.')
        ->and($a['imageLink'])->toBe("https://shop.example.com/storage/product/{$parent->id}/parent.webp")
        ->and($a['brand'])->toBe('OmniHeat');
});

it('prefers a variant own name and image', function () {
    $parent = configurableProduct(1);
    $variant = $parent->variants()->first();

    setProductAttribute($variant, 'name', 'Puffer Jacket Red Small');
    addImage($variant, 'red.webp');

    $a = ($this->map)($parent->fresh())[0]->productInput['productAttributes'];

    expect($a['title'])->toBe('Puffer Jacket Red Small')
        ->and($a['imageLink'])->toBe("https://shop.example.com/storage/product/{$variant->id}/red.webp")
        ->and($a['additionalImageLinks'])->toBe(["https://shop.example.com/storage/product/{$parent->id}/parent.webp"]);
});

it('emits sale price only when lower', function () {
    $product = simpleProduct([
        'special_price'      => 17,
        'special_price_from' => now()->subDay()->toDateString(),
        'special_price_to'   => now()->addDays(5)->toDateString(),
    ]);

    $a = ($this->map)($product)[0]->productInput['productAttributes'];

    expect($a['salePrice'])->toBe(['amountMicros' => '17000000', 'currencyCode' => 'USD'])
        ->and($a['salePriceEffectiveDate']['startTime'])->toStartWith(now()->subDay()->toDateString())
        ->and($a['salePriceEffectiveDate']['endTime'])->toStartWith(now()->addDays(5)->toDateString());

    $higher = simpleProduct(['special_price' => 25]);

    expect(($this->map)($higher)[0]->productInput['productAttributes'])->not->toHaveKey('salePrice');
});

it('ignores an expired special price', function () {
    $product = simpleProduct([
        'special_price'      => 17,
        'special_price_from' => now()->subDays(10)->toDateString(),
        'special_price_to'   => now()->subDays(2)->toDateString(),
    ]);

    expect(($this->map)($product)[0]->productInput['productAttributes'])->not->toHaveKey('salePrice');
});

it('uses a lower catalog-rule index price as sale price without dates', function () {
    $product = simpleProduct();

    DB::table('product_price_indices')
        ->where('product_id', $product->id)
        ->where('customer_group_id', DB::table('customer_groups')->where('code', 'guest')->value('id'))
        ->update(['min_price' => 18.5]);

    $a = ($this->map)($product)[0]->productInput['productAttributes'];

    expect($a['salePrice']['amountMicros'])->toBe('18500000')
        ->and($a)->not->toHaveKey('salePriceEffectiveDate');
});

it('marks out of stock when salable qty is 0', function () {
    $product = simpleProduct();

    setStock($product, 0);

    expect(($this->map)($product)[0]->productInput['productAttributes']['availability'])->toBe('OUT_OF_STOCK');

    setProductAttribute($product, 'manage_stock', 0);

    expect(($this->map)($product->fresh())[0]->productInput['productAttributes']['availability'])->toBe('IN_STOCK');
});

it('sends identifierExists false only when the admin set it to No', function () {
    $product = simpleProduct(['gtin' => null, 'product_number' => null, 'gmc_identifier_exists' => 0]);

    $a = ($this->map)($product)[0]->productInput['productAttributes'];

    expect($a['identifierExists'])->toBeFalse()
        ->and($a)->not->toHaveKey('gtins');
});

it('maps apparel attributes and labels', function () {
    $product = simpleProduct([
        'gmc_condition'      => optionId('gmc_condition', 'refurbished'),
        'gmc_gender'         => optionId('gmc_gender', 'unisex'),
        'gmc_age_group'      => optionId('gmc_age_group', 'adult'),
        'gmc_material'       => 'Wool',
        'gmc_pattern'        => 'Cable knit',
        'gmc_google_category' => '173',
        'gmc_custom_label_0' => 'winter',
        'gmc_multipack'      => '2',
        'weight'             => '0.3',
    ]);

    $a = ($this->map)($product)[0]->productInput['productAttributes'];

    expect($a['condition'])->toBe('REFURBISHED')
        ->and($a['gender'])->toBe('UNISEX')
        ->and($a['ageGroup'])->toBe('ADULT')
        ->and($a['material'])->toBe('Wool')
        ->and($a['pattern'])->toBe('Cable knit')
        ->and($a['googleProductCategory'])->toBe('173')
        ->and($a['customLabel0'])->toBe('winter')
        ->and($a['multipack'])->toBe('2')
        ->and($a['shippingWeight']['value'])->toEqual(0.3);
});

it('falls back to the configured brand', function () {
    gmcConfig(['settings.default_brand' => 'House Brand']);

    $product = simpleProduct(['brand' => null]);

    expect(($this->map)($product)[0]->productInput['productAttributes']['brand'])->toBe('House Brand');
});

it('truncates title and description to Google limits', function () {
    $product = simpleProduct(['name' => str_repeat('a', 200), 'description' => str_repeat('b ', 3000)]);

    $a = ($this->map)($product)[0]->productInput['productAttributes'];

    expect(mb_strlen($a['title']))->toBeLessThanOrEqual(150)
        ->and(mb_strlen($a['description']))->toBeLessThanOrEqual(5000);
});

it('sends bundle as one offer with isBundle', function () {
    $bundle = (new ProductFaker)->getBundleProductFactory()->create();

    setProductAttribute($bundle, 'url_key', 'bundle-'.$bundle->id);
    reindexPrices($bundle);

    $offers = ($this->map)($bundle->fresh());

    expect($offers)->toHaveCount(1)
        ->and($offers[0]->productInput['productAttributes']['isBundle'])->toBeTrue()
        ->and($offers[0]->productInput['productAttributes']['price']['amountMicros'])->not->toBe('0');
});

it('skips grouped products', function () {
    $grouped = (new ProductFaker)->getGroupedProductFactory()->create();

    expect(($this->map)($grouped))->toBe([]);
});

it('hash is stable and changes with price', function () {
    $product = simpleProduct();

    $first = ($this->map)($product)[0]->hash;

    expect(($this->map)($product->fresh())[0]->hash)->toBe($first);

    setProductAttribute($product, 'price', 22);

    expect(($this->map)($product->fresh())[0]->hash)->not->toBe($first);
});

it('never truncates the sku', function () {
    $product = simpleProduct();

    DB::table('products')->where('id', $product->id)->update(['sku' => $sku = str_repeat('x', 60)]);

    expect(($this->map)($product->fresh())[0]->offerId)->toBe($sku);
});
