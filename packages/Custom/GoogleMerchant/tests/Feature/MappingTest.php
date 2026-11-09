<?php

require_once __DIR__.'/../Helpers.php';

use Custom\GoogleMerchant\Jobs\RefreshRunJob;
use Custom\GoogleMerchant\Mapping\MappingRegistry;
use Custom\GoogleMerchant\Mapping\ProductMapper;
use Custom\GoogleMerchant\Models\GmcAttributeMapping;
use Custom\GoogleMerchant\Models\GmcCategoryMapping;
use Custom\GoogleMerchant\Support\Taxonomy;
use Custom\GoogleMerchant\Sync\Eligibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\put;

beforeEach(function () {
    gmcConfig(['settings.enabled' => 1, 'store.website_url' => 'https://shop.example.com']);
    config(['google_merchant.taxonomy_path' => __DIR__.'/../Fixtures/taxonomy-with-ids.en-US.txt']);

    $this->target = gmcTarget(gmcAccount());
    $this->attrs = fn ($product) => app(ProductMapper::class)->map($product->fresh(), $this->target)[0]->attributes();

    $this->categoryId = DB::table('categories')->whereNotNull('parent_id')->value('id');
});

it('applies a static mapping', function () {
    GmcAttributeMapping::create(['google_field' => 'condition', 'source_type' => 'static', 'source_value' => 'used']);

    expect(($this->attrs)(simpleProduct())['condition'])->toBe('USED');
});

it('maps a google field to another store attribute', function () {
    GmcAttributeMapping::create(['google_field' => 'brand', 'source_type' => 'attribute', 'source_value' => 'gmc_material']);

    expect(($this->attrs)(simpleProduct(['gmc_material' => 'Merino Co']))['brand'])->toBe('Merino Co');
});

it('omits a field mapped to none, without falling back', function () {
    GmcAttributeMapping::create(['google_field' => 'condition', 'source_type' => 'none']);
    GmcAttributeMapping::create(['google_field' => 'mpn', 'source_type' => 'none']);

    expect(($this->attrs)(simpleProduct()))->not->toHaveKeys(['condition', 'mpn']);
});

it('prefers a target-specific mapping over the global one', function () {
    GmcAttributeMapping::create(['google_field' => 'customLabel0', 'source_type' => 'static', 'source_value' => 'global']);
    GmcAttributeMapping::create(['google_field' => 'customLabel0', 'source_type' => 'static', 'source_value' => 'us-only', 'gmc_target_id' => $this->target->id]);

    expect(app(MappingRegistry::class)->sourceFor('customLabel0', $this->target))->toBe(['type' => 'static', 'value' => 'us-only']);
});

it('uses the category mapping for google product category', function () {
    $product = simpleProduct();
    DB::table('product_categories')->insert(['product_id' => $product->id, 'category_id' => $this->categoryId]);
    GmcCategoryMapping::create(['category_id' => $this->categoryId, 'google_product_category' => '1604']);

    expect(($this->attrs)($product)['googleProductCategory'])->toBe('1604');
});

it('excludes products of an excluded category', function () {
    $product = simpleProduct();
    DB::table('product_categories')->insert(['product_id' => $product->id, 'category_id' => $this->categoryId]);
    GmcCategoryMapping::create(['category_id' => $this->categoryId, 'excluded' => true]);

    expect(app(Eligibility::class)->reason($product->fresh(), $this->target))->toBe('category_excluded');
});

it('saves attribute mappings from the admin and queues a refresh', function () {
    $this->loginAsAdmin();
    Queue::fake();

    get(route('admin.google_merchant.mapping.attributes'))->assertOk()->assertSeeText(trans('google_merchant::app.mapping.attributes-title'));

    put(route('admin.google_merchant.mapping.attributes.update'), ['mappings' => [
        'condition' => ['type' => 'static', 'value' => 'refurbished'],
        'brand'     => ['type' => 'attribute', 'value' => 'brand'], // same as default → no row
        'mpn'       => ['type' => 'none', 'value' => ''],
    ]])->assertRedirect(route('admin.google_merchant.mapping.attributes'));

    expect(GmcAttributeMapping::whereNull('gmc_target_id')->pluck('source_type', 'google_field')->all())->toEqualCanonicalizing(['condition' => 'static', 'mpn' => 'none'])
        ->and(DB::table('google_audit_logs')->where('action', 'mapping_attributes')->exists())->toBeTrue();

    Queue::assertPushed(RefreshRunJob::class);
});

it('rejects a mapping to an unknown attribute code', function () {
    $this->loginAsAdmin();

    put(route('admin.google_merchant.mapping.attributes.update'), ['mappings' => [
        'brand' => ['type' => 'attribute', 'value' => 'no_such_attribute'],
    ]])->assertSessionHasErrors('mappings.brand.value');

    put(route('admin.google_merchant.mapping.attributes.update'), ['mappings' => [
        'notAGoogleField' => ['type' => 'static', 'value' => 'x'],
    ]])->assertSessionHasErrors('mappings');

    expect(GmcAttributeMapping::count())->toBe(0);
});

it('saves category mappings from the admin', function () {
    $this->loginAsAdmin();
    Queue::fake();

    get(route('admin.google_merchant.mapping.categories'))->assertOk()->assertSeeText(trans('google_merchant::app.mapping.categories-title'));

    put(route('admin.google_merchant.mapping.categories.update'), ['categories' => [
        $this->categoryId => ['google_product_category' => '212', 'excluded' => '1'],
    ]])->assertRedirect(route('admin.google_merchant.mapping.categories'));

    $row = GmcCategoryMapping::where('category_id', $this->categoryId)->first();

    expect($row->google_product_category)->toBe('212')->and($row->excluded)->toBeTrue();

    put(route('admin.google_merchant.mapping.categories.update'), ['categories' => [
        $this->categoryId => ['google_product_category' => 'not-an-id'],
    ]])->assertSessionHasErrors("categories.{$this->categoryId}.google_product_category");
});

it('searches the google taxonomy', function () {
    $this->loginAsAdmin();

    $results = getJson(route('admin.google_merchant.mapping.taxonomy', ['q' => 'shirt']))->assertOk()->json();

    expect($results)->toBe([['id' => '212', 'path' => 'Apparel & Accessories > Clothing > Shirts & Tops']])
        ->and(app(Taxonomy::class)->pathFor('1604'))->toBe('Apparel & Accessories > Clothing')
        ->and(getJson(route('admin.google_merchant.mapping.taxonomy', ['q' => '5598']))->json('0.id'))->toBe('5598');
});

it('works without a taxonomy file', function () {
    config(['google_merchant.taxonomy_path' => storage_path('missing-taxonomy.txt')]);

    expect(app(Taxonomy::class)->search('shirt'))->toBe([])
        ->and(app(Taxonomy::class)->available())->toBeFalse();
});

it('installs the taxonomy from a file and rejects junk', function () {
    $target = storage_path('framework/testing/gmc-taxonomy-'.uniqid().'.txt');
    config(['google_merchant.taxonomy_path' => $target]);

    expect(\Illuminate\Support\Facades\Artisan::call('gmc:taxonomy', ['--from' => __DIR__.'/../Fixtures/token.json']))->toBe(1)
        ->and(is_file($target))->toBeFalse();

    expect(\Illuminate\Support\Facades\Artisan::call('gmc:taxonomy', ['--from' => __DIR__.'/../Fixtures/taxonomy-with-ids.en-US.txt']))->toBe(0)
        ->and(app(Taxonomy::class)->pathFor('173'))->toBe('Apparel & Accessories > Clothing Accessories > Hats');

    @unlink($target);
});
