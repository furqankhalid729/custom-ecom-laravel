<?php

use Custom\GoogleMerchant\Support\Micros;

it('converts amounts to int64 micros strings', function (float|string $amount, string $expected) {
    expect(Micros::fromAmount($amount))->toBe($expected);
})->with([
    [14, '14000000'],
    ['19.99', '19990000'],
    [0.1, '100000'],
    ['107.0000', '107000000'],
    [0.29, '290000'],
]);
