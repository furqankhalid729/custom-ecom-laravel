<?php

namespace Custom\GoogleMerchant\Support;

/**
 * Product attributes added to every attribute family under "Google Shopping".
 */
class GoogleAttributes
{
    public const GROUP_CODE = 'google_shopping';

    public const GROUP_NAME = 'Google Shopping';

    /**
     * @return array<string, array{name: string, type: string, default?: string, validation?: string, options?: string[]}>
     */
    public static function definitions(): array
    {
        $definitions = [
            'gmc_sync'              => ['name' => 'Sync to Google Merchant Center', 'type' => 'boolean', 'default' => '1'],
            'gtin'                  => ['name' => 'GTIN (UPC / EAN / ISBN)', 'type' => 'text'],
            'gmc_identifier_exists' => ['name' => 'Identifier exists (No only for custom-made items without GTIN/MPN)', 'type' => 'boolean', 'default' => '1'],
            'gmc_condition'         => ['name' => 'Condition', 'type' => 'select', 'options' => ['new', 'refurbished', 'used']],
            'gmc_google_category'   => ['name' => 'Google product category (ID)', 'type' => 'text'],
            'gmc_gender'            => ['name' => 'Gender', 'type' => 'select', 'options' => ['male', 'female', 'unisex']],
            'gmc_age_group'         => ['name' => 'Age group', 'type' => 'select', 'options' => ['adult', 'kids', 'toddler', 'infant', 'newborn']],
            'gmc_material'          => ['name' => 'Material', 'type' => 'text'],
            'gmc_pattern'           => ['name' => 'Pattern', 'type' => 'text'],
            'gmc_multipack'         => ['name' => 'Multipack quantity', 'type' => 'text', 'validation' => 'numeric'],
        ];

        foreach (range(0, 4) as $i) {
            $definitions["gmc_custom_label_$i"] = ['name' => "Custom label $i", 'type' => 'text'];
        }

        return $definitions;
    }
}
