<?php

namespace Custom\GoogleMerchant\Mapping;

use Custom\GoogleMerchant\Models\GmcAttributeMappingProxy;
use Custom\GoogleMerchant\Models\GmcTarget;

/**
 * Where each Google field comes from: an admin mapping (target-specific,
 * then global) or the built-in default. Resolved once per instance; the
 * mapper is created per job, so admin changes apply to the next sync.
 */
class MappingRegistry
{
    public const SOURCE_TYPES = ['attribute', 'static', 'none'];

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

    /** Used when an attribute-sourced field is empty on the product. */
    public const STATIC_FALLBACKS = [
        'condition' => 'new',
    ];

    /** @var array<string, array<string, array{type: string, value: ?string}>> target key => field => source */
    protected array $resolved = [];

    /**
     * @return array{type: 'attribute'|'static'|'none', value: ?string}
     */
    public function sourceFor(string $googleField, GmcTarget $target): array
    {
        $key = (string) $target->id;

        if (! isset($this->resolved[$key])) {
            $this->resolved[$key] = $this->load($target);
        }

        return $this->resolved[$key][$googleField] ?? ['type' => 'none', 'value' => null];
    }

    /** Fallback only applies to attribute-sourced fields (an explicit "none" stays empty). */
    public function fallbackFor(string $googleField, ?GmcTarget $target = null): ?string
    {
        if ($target && $this->sourceFor($googleField, $target)['type'] !== 'attribute') {
            return null;
        }

        return self::STATIC_FALLBACKS[$googleField] ?? null;
    }

    /** @return array<string, array{type: string, value: ?string}> */
    protected function load(GmcTarget $target): array
    {
        $sources = array_map(fn ($d) => ['type' => $d[0], 'value' => $d[1]], self::DEFAULTS);

        $rows = GmcAttributeMappingProxy::modelClass()::query()
            ->where(fn ($q) => $q->whereNull('gmc_target_id')->orWhere('gmc_target_id', $target->id))
            ->whereIn('google_field', array_keys(self::DEFAULTS))
            ->orderByRaw('gmc_target_id IS NULL DESC') // global first, target-specific overrides it
            ->get();

        foreach ($rows as $row) {
            $sources[$row->google_field] = [
                'type'  => in_array($row->source_type, self::SOURCE_TYPES, true) ? $row->source_type : 'none',
                'value' => $row->source_value,
            ];
        }

        return $sources;
    }
}
