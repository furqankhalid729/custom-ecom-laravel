<?php

require_once __DIR__.'/../Helpers.php';

use Custom\GoogleMerchant\Models\GmcProduct;
use Custom\GoogleMerchant\Sync\SyncService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    gmcConfig(['settings.enabled' => 1, 'store.website_url' => 'https://shop.example.com']);

    $this->target = gmcTarget(gmcAccount());
    $this->sync = fn ($product, bool $force = false) => app(SyncService::class)->syncRoot($product->id, $this->target->id, $force);

    $this->calls = fn () => collect(Http::recorded())->map(fn ($pair) => [
        'method' => $pair[0]->method(),
        'url'    => urldecode($pair[0]->url()),
        'body'   => $pair[0]->data(),
    ]);

    // Tests swap $this->respond to script Google's answers (fake stubs match in registration order).
    $this->respond = fn ($request) => Http::response(fixture('productinput-insert'));
    Http::fake(fn ($request) => ($this->respond)($request));
});

it('patches only availability when just the stock changed', function () {
    $product = simpleProduct();
    ($this->sync)($product);

    setStock($product, 0);
    ($this->sync)($product);

    $last = ($this->calls)()->last();

    expect(($this->calls)())->toHaveCount(2)
        ->and($last['method'])->toBe('PATCH')
        ->and($last['url'])->toContain('updateMask=productAttributes.availability')
        ->and($last['url'])->toContain('dataSource=accounts/123456/dataSources/999')
        ->and($last['body']['productAttributes'])->toBe(['availability' => 'OUT_OF_STOCK']);

    expect(GmcProduct::where('product_id', $product->id)->value('state'))->toBe('synced');
});

it('patches price fields together and clears an ended sale', function () {
    $product = simpleProduct(['special_price' => 17]);
    ($this->sync)($product);

    setProductAttribute($product, 'special_price', null);
    setProductAttribute($product, 'price', 23);
    reindexPrices($product);
    ($this->sync)($product);

    $last = ($this->calls)()->last();

    expect($last['method'])->toBe('PATCH')
        ->and($last['url'])->toContain('updateMask=productAttributes.price,productAttributes.salePrice')
        ->and($last['body']['productAttributes'])->toBe(['price' => ['amountMicros' => '23000000', 'currencyCode' => 'USD']]);
});

it('sends a full insert when anything else changed', function () {
    $product = simpleProduct();
    ($this->sync)($product);

    setProductAttribute($product, 'name', 'Renamed beanie');
    setStock($product, 0);
    ($this->sync)($product);

    expect(($this->calls)()->pluck('method')->all())->toBe(['POST', 'POST']);
});

it('falls back to an insert when google no longer has the offer', function () {
    $product = simpleProduct();
    ($this->sync)($product);

    $this->respond = fn ($request) => $request->method() === 'PATCH'
        ? Http::response(fixture('error-404'), 404)
        : Http::response(fixture('productinput-insert'));

    setStock($product, 0);
    ($this->sync)($product);

    expect(($this->calls)()->pluck('method')->all())->toBe(['POST', 'PATCH', 'POST'])
        ->and(GmcProduct::where('product_id', $product->id)->value('state'))->toBe('synced');
});

it('never patches a forced sync or an offer due for resubmission', function () {
    $product = simpleProduct();
    ($this->sync)($product);

    setStock($product, 0);
    ($this->sync)($product, true);

    setStock($product, 3);
    GmcProduct::where('product_id', $product->id)->update(['last_synced_at' => now()->subDays(26)]);
    ($this->sync)($product);

    expect(($this->calls)()->pluck('method')->all())->toBe(['POST', 'POST', 'POST']);
});

it('keeps the resubmission clock running after a patch', function () {
    $product = simpleProduct();
    ($this->sync)($product);

    $insertedAt = GmcProduct::where('product_id', $product->id)->value('last_synced_at');

    $this->travel(2)->hours();
    setStock($product, 0);
    ($this->sync)($product);

    expect((string) GmcProduct::where('product_id', $product->id)->value('last_synced_at'))->toBe((string) $insertedAt);
});
