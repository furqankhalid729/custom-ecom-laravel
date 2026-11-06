<?php

namespace Custom\StorePolicy\Support;

class Regions
{
    public const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE',
        'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
    ];

    public const COUNTRY_NAMES = [
        'AT' => 'Austria', 'BE' => 'Belgium', 'BG' => 'Bulgaria', 'HR' => 'Croatia', 'CY' => 'Cyprus',
        'CZ' => 'Czechia', 'DK' => 'Denmark', 'EE' => 'Estonia', 'FI' => 'Finland', 'FR' => 'France',
        'DE' => 'Germany', 'GR' => 'Greece', 'HU' => 'Hungary', 'IE' => 'Ireland', 'IT' => 'Italy',
        'LV' => 'Latvia', 'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'MT' => 'Malta', 'NL' => 'Netherlands',
        'PL' => 'Poland', 'PT' => 'Portugal', 'RO' => 'Romania', 'SK' => 'Slovakia', 'SI' => 'Slovenia',
        'ES' => 'Spain', 'SE' => 'Sweden', 'US' => 'the United States',
    ];

    /**
     * Human-readable list of the countries a region ships to.
     */
    public static function countriesText(string $code, array $countries): string
    {
        if ($code === 'EU' && count(array_intersect(self::EU_COUNTRIES, $countries)) === count(self::EU_COUNTRIES)) {
            return 'all EU member states';
        }

        $names = array_map(fn ($country) => self::COUNTRY_NAMES[$country] ?? $country, $countries);

        if (count($names) <= 1) {
            return implode('', $names);
        }

        $last = array_pop($names);

        return implode(', ', $names).' and '.$last;
    }

    public static function definitions(): array
    {
        return [
            'EU' => ['countries' => self::EU_COUNTRIES, 'currency' => 'EUR', 'label' => 'European Union'],
            'US' => ['countries' => ['US'], 'currency' => 'USD', 'label' => 'United States'],
        ];
    }

    public static function label(string $code): string
    {
        return self::definitions()[$code]['label'] ?? $code;
    }

    /**
     * Enabled regions from a settings array, keyed by code, each with its label added.
     */
    public static function enabled(array $settings): array
    {
        $regions = [];

        foreach ($settings['regions'] ?? [] as $code => $region) {
            if (! empty($region['enabled'])) {
                $regions[$code] = $region + ['label' => self::label($code)];
            }
        }

        return $regions;
    }
}
