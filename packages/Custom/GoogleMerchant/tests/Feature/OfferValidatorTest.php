<?php

require_once __DIR__.'/../Helpers.php';

use Custom\GoogleMerchant\Mapping\ProductMapper;
use Custom\GoogleMerchant\Validation\OfferValidator;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    gmcConfig(['store.website_url' => 'https://shop.example.com']);

    $this->target = gmcTarget(gmcAccount());

    $this->offer = fn ($product) => app(ProductMapper::class)->map($product->fresh(), $this->target)[0];
    $this->errors = fn ($product) => app(OfferValidator::class)->validate(($this->offer)($product), $this->target);
});

it('accepts a valid simple product', function () {
    expect(($this->errors)(simpleProduct()))->toBe([]);
});

it('requires an image', function () {
    $product = simpleProduct();

    DB::table('product_images')->where('product_id', $product->id)->delete();

    expect(($this->errors)($product))->toHaveKey('imageLink');
});

it('requires a public https store url', function () {
    gmcConfig(['store.website_url' => 'http://localhost']);

    $errors = ($this->errors)(simpleProduct());

    expect($errors)->toHaveKey('link')
        ->and($errors['link'])->toBe(trans('google_merchant::app.validation.public-https', ['url' => 'http://localhost']));
});

it('requires a brand while identifiers exist', function () {
    expect(($this->errors)(simpleProduct(['brand' => null])))->toHaveKey('brand');
});

it('requires a gtin or brand plus mpn and never auto-sets identifierExists', function () {
    $product = simpleProduct(['gtin' => null, 'product_number' => null]);

    expect(($this->errors)($product))->toHaveKey('gtins')
        ->and(($this->offer)($product)->attributes())->not->toHaveKey('identifierExists');
});

it('accepts brand plus mpn without a gtin', function () {
    expect(($this->errors)(simpleProduct(['gtin' => null])))->toBe([]);
});

it('rejects a gtin with a bad check digit', function () {
    expect(($this->errors)(simpleProduct(['gtin' => '4006381333932'])))->toHaveKey('gtins');
});

it('accepts identifier exists No without gtin or brand', function () {
    $product = simpleProduct(['gtin' => null, 'product_number' => null, 'brand' => null, 'gmc_identifier_exists' => 0]);

    expect(($this->errors)($product))->toBe([])
        ->and(($this->offer)($product)->attributes()['identifierExists'])->toBeFalse();
});

it('rejects identifier exists No together with a gtin', function () {
    $errors = ($this->errors)(simpleProduct(['gmc_identifier_exists' => 0]));

    expect($errors)->toHaveKey('gtins')
        ->and($errors['gtins'])->toBe(trans('google_merchant::app.validation.identifier-contradiction'));
});

it('rejects a sku longer than 50 characters without truncating it', function () {
    $product = simpleProduct();

    DB::table('products')->where('id', $product->id)->update(['sku' => $sku = str_repeat('s', 51)]);

    expect(($this->errors)($product))->toHaveKey('offerId')
        ->and(($this->offer)($product)->offerId)->toBe($sku);
});

it('rejects a sku with surrounding whitespace', function () {
    $product = simpleProduct();

    DB::table('products')->where('id', $product->id)->update(['sku' => ' padded ']);

    expect(($this->errors)($product))->toHaveKey('offerId');
});

it('requires a price', function () {
    expect(($this->errors)(simpleProduct(['price' => 0])))->toHaveKey('price');
});

it('requires apparel attributes for US apparel', function () {
    $errors = ($this->errors)(simpleProduct(['gmc_google_category' => '1604']));

    expect($errors)->toHaveKeys(['color', 'size', 'gender', 'ageGroup']);
});
