<?php

namespace Custom\GoogleMerchant\Support;

class Micros
{
    /** Merchant API Price.amountMicros: int64 as a decimal string, 1 unit = 1,000,000 micros. */
    public static function fromAmount(float|int|string $amount): string
    {
        return number_format(round((float) $amount * 1_000_000), 0, '', '');
    }

    /** @return array{amountMicros: string, currencyCode: string} */
    public static function price(float|int|string $amount, string $currency): array
    {
        return ['amountMicros' => self::fromAmount($amount), 'currencyCode' => $currency];
    }
}
