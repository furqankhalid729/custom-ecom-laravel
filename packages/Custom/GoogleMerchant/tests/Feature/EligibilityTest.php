<?php

require_once __DIR__.'/../Helpers.php';

use Custom\GoogleMerchant\Mapping\ProductMapper;
use Custom\GoogleMerchant\Sync\Eligibility;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    gmcConfig(['settings.enabled' => 1, 'store.website_url' => 'https://shop.example.com']);

    $this->target = gmcTarget(gmcAccount());
    $this->reason = fn ($product) => app(Eligibility::class)->reason($product->fresh(), $this->target);
});

it('accepts an enabled, visible simple product', function () {
    expect(($this->reason)(simpleProduct()))->toBeNull();
});

it('treats products saved before the gmc_sync attribute existed as included', function () {
    $product = simpleProduct();

    DB::table('product_attribute_values')
        ->where('product_id', $product->id)
        ->where('attribute_id', DB::table('attributes')->where('code', 'gmc_sync')->value('id'))
        ->delete();

    expect(($this->reason)($product))->toBeNull();
});

it('excludes when sync is switched off globally', function () {
    gmcConfig(['settings.enabled' => 0]);

    expect(($this->reason)(simpleProduct()))->toBe('disabled_globally');
});

it('excludes when the product sync flag is off', function () {
    expect(($this->reason)(simpleProduct(['gmc_sync' => 0])))->toBe('sync_off');
});

it('excludes disabled products', function () {
    expect(($this->reason)(simpleProduct(['status' => 0])))->toBe('product_disabled');
});

it('excludes products not visible individually', function () {
    expect(($this->reason)(simpleProduct(['visible_individually' => 0])))->toBe('not_visible');
});

it('excludes configured product types', function () {
    $product = simpleProduct();

    DB::table('products')->where('id', $product->id)->update(['type' => 'virtual']);

    expect(($this->reason)($product))->toBe('type_excluded');

    gmcConfig(['settings.excluded_types' => 'booking']);

    expect(($this->reason)($product))->toBeNull();
});

it('excludes products in an excluded category', function () {
    $product = simpleProduct();

    $categoryId = DB::table('categories')->whereNotNull('parent_id')->value('id');

    DB::table('product_categories')->insert(['product_id' => $product->id, 'category_id' => $categoryId]);
    DB::table('gmc_category_mappings')->insert(['category_id' => $categoryId, 'excluded' => true, 'created_at' => now(), 'updated_at' => now()]);

    expect(($this->reason)($product))->toBe('category_excluded');
});

it('drops disabled variants from the offers', function () {
    $parent = configurableProduct(3);

    $variant = $parent->variants()->orderBy('id')->first();
    setProductAttribute($variant, 'status', 0);

    $offers = app(ProductMapper::class)->map($parent->fresh(), $this->target);

    $kept = app(Eligibility::class)->eligibleOffers($offers, $this->target);

    expect($kept)->toHaveCount(2)
        ->and(collect($kept)->pluck('productId'))->not->toContain($variant->id);
});

it('removes out-of-stock products only when configured to', function () {
    $product = simpleProduct();
    setStock($product, 0);

    $offers = app(ProductMapper::class)->map($product->fresh(), $this->target);

    expect(app(Eligibility::class)->stockReason($offers))->toBeNull();

    gmcConfig(['settings.out_of_stock_action' => 'delete']);

    expect(app(Eligibility::class)->stockReason($offers))->toBe('out_of_stock_delete');
});
