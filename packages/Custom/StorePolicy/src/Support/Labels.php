<?php

namespace Custom\StorePolicy\Support;

/**
 * Policy wording for enumerated settings values (English).
 */
class Labels
{
    public const CONDITIONS = [
        'unused'             => 'unused and in the same condition you received them',
        'original_packaging' => 'in their original packaging',
        'tags_attached'      => 'with all tags attached',
    ];

    public const RETURN_METHOD = [
        'mail'  => 'Returns are accepted by mail.',
        'store' => 'Returns are accepted in store.',
        'both'  => 'Returns are accepted by mail or in store.',
    ];

    public const RETURN_SHIPPING = [
        'customer' => 'You are responsible for the cost of returning items to us.',
        'store'    => 'We cover the cost of return shipping and will send you a prepaid return label.',
        'free'     => 'Returns are free of charge.',
    ];

    public const REFUND_METHOD = [
        'original'        => 'to your original payment method',
        'store_credit'    => 'as store credit',
        'customer_choice' => 'to your original payment method or as store credit, whichever you prefer',
    ];

    public const CHARGED_AT = [
        'order'    => 'Your payment method is charged when you place your order.',
        'shipment' => 'Your payment method is authorised when you place your order and charged when your order ships.',
    ];

    public static function conditions(array $conditions): string
    {
        $parts = array_values(array_filter(array_map(fn ($c) => self::CONDITIONS[$c] ?? null, $conditions)));

        if (count($parts) <= 1) {
            return implode('', $parts);
        }

        $last = array_pop($parts);

        return implode(', ', $parts).' and '.$last;
    }

    public static function days(int|string|null $min, int|string|null $max): string
    {
        return (int) $min === (int) $max ? (int) $max.' business days' : (int) $min.'–'.(int) $max.' business days';
    }
}
