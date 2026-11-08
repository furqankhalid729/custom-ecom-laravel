<?php

use Custom\GoogleMerchant\Validation\Gtin;

it('validates GS1 check digits', function (string $gtin, bool $valid) {
    expect(Gtin::isValid($gtin))->toBe($valid);
})->with([
    ['4006381333931', true],   // EAN-13
    ['4006381333932', false],  // bad check digit
    ['012345678905', true],    // UPC-A
    ['96385074', true],        // EAN-8
    ['00012345678905', true],  // GTIN-14
    ['00000000', false],       // all zeros
    ['abc', false],
    ['12345', false],          // bad length
]);
