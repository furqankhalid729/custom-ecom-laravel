<?php

namespace Custom\StorePolicy\Support;

class RequiredFields
{
    /**
     * Required fields that apply regardless of region.
     */
    public const GLOBAL = [
        'business.legal_name',
        'business.address.street',
        'business.address.city',
        'business.address.postcode',
        'business.address.country',
        'business.email',
        'business.phone',
        'terms.governing_law',
    ];

    /**
     * Required fields for each enabled region (relative to regions.{code}).
     */
    public const REGION = [
        'returns.window_days',
        'returns.refund_days',
        'shipping.handling_min_days',
        'shipping.handling_max_days',
        'shipping.methods',
    ];

    /**
     * Dotted keys of required fields that are empty.
     */
    public static function missing(array $settings): array
    {
        $paths = self::GLOBAL;

        foreach (Regions::enabled($settings) as $code => $region) {
            foreach (self::REGION as $path) {
                $paths[] = "regions.{$code}.{$path}";
            }
        }

        return array_values(array_filter($paths, fn ($path) => self::isEmpty(data_get($settings, $path))));
    }

    protected static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
