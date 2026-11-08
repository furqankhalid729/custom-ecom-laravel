<?php

use Custom\GoogleMerchant\Support\ProductInputName;

it('encodes the documented example as unpadded base64url', function () {
    expect(ProductInputName::encode('123', 'en', 'US', 'sku/123'))
        ->toBe('accounts/123/productInputs/ZW5-VVN-c2t1LzEyMw');
});

it('never emits padding or url-unsafe characters', function () {
    $name = ProductInputName::encode('123', 'en', 'US', 'AB/1~x');

    $id = substr($name, strlen('accounts/123/productInputs/'));

    expect($name)->toStartWith('accounts/123/productInputs/')
        ->and($id)->not->toContain('=')
        ->and($id)->not->toContain('+')
        ->and($id)->not->toContain('/')
        ->and(base64_decode(strtr($id, '-_', '+/')))->toBe('en~US~AB/1~x');
});
