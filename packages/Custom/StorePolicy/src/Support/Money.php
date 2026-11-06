<?php

namespace Custom\StorePolicy\Support;

class Money
{
    protected const SYMBOLS = [
        'EUR' => '€',
        'USD' => '$',
        'GBP' => '£',
    ];

    /**
     * Format an amount in a region currency, independent of the store's installed currencies.
     */
    public static function format(float|int|string|null $amount, string $currency): string
    {
        $value = number_format((float) $amount, 2, '.', ',');

        $symbol = self::SYMBOLS[strtoupper($currency)] ?? null;

        return $symbol ? $symbol.$value : strtoupper($currency).' '.$value;
    }
}
