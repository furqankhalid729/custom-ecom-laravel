<?php

namespace Custom\GoogleMerchant\Mapping;

use Custom\GoogleMerchant\Models\GmcTarget;

/**
 * Where each Google field comes from. Phase 1 uses these defaults only;
 * gmc_attribute_mappings overrides plug in here later.
 */
class MappingRegistry
{
    /** google field => [source type, source value] */
    public const DEFAULTS = [
        'title'                 => ['attribute', 'name'],
        'description'           => ['attribute', 'description'],
        'brand'                 => ['attribute', 'brand'],
        'gtin'                  => ['attribute', 'gtin'],
        'mpn'                   => ['attribute', 'product_number'],
        'identifierExists'      => ['attribute', 'gmc_identifier_exists'],
        'condition'             => ['attribute', 'gmc_condition'],
        'googleProductCategory' => ['attribute', 'gmc_google_category'],
        'color'                 => ['attribute', 'color'],
        'size'                  => ['attribute', 'size'],
        'gender'                => ['attribute', 'gmc_gender'],
        'ageGroup'              => ['attribute', 'gmc_age_group'],
        'material'              => ['attribute', 'gmc_material'],
        'pattern'               => ['attribute', 'gmc_pattern'],
        'multipack'             => ['attribute', 'gmc_multipack'],
        'customLabel0'          => ['attribute', 'gmc_custom_label_0'],
        'customLabel1'          => ['attribute', 'gmc_custom_label_1'],
        'customLabel2'          => ['attribute', 'gmc_custom_label_2'],
        'customLabel3'          => ['attribute', 'gmc_custom_label_3'],
        'customLabel4'          => ['attribute', 'gmc_custom_label_4'],
    ];

    /** Used when the mapped source is empty. */
    public const STATIC_FALLBACKS = [
        'condition' => 'new',
    ];

    /**
     * @return array{type: 'attribute'|'category'|'static'|'none', value: ?string}
     */
    public function sourceFor(string $googleField, GmcTarget $target): array
    {
        [$type, $value] = self::DEFAULTS[$googleField] ?? ['none', null];

        return ['type' => $type, 'value' => $value];
    }

    public function fallbackFor(string $googleField): ?string
    {
        return self::STATIC_FALLBACKS[$googleField] ?? null;
    }
}
