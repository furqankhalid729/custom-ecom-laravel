<?php

namespace Custom\GoogleMerchant\Validation;

class Gtin
{
    /** GTIN-8/12/13/14 with a valid GS1 mod-10 check digit. */
    public static function isValid(string $gtin): bool
    {
        if (! preg_match('/^(\d{8}|\d{12}|\d{13}|\d{14})$/', $gtin) || ltrim($gtin, '0') === '') {
            return false;
        }

        $digits = array_map('intval', str_split($gtin));
        $check = array_pop($digits);

        $sum = 0;

        foreach (array_reverse($digits) as $i => $digit) {
            $sum += $digit * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === $check;
    }
}
