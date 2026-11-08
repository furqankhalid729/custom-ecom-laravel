<?php

use Custom\GoogleMerchant\Support\GoogleAttributes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the Google Shopping attributes and puts them in a "Google Shopping"
 * group of every attribute family. Safe to run twice; existing attribute
 * codes (e.g. a hand-made `gtin`) are reused, never duplicated.
 */
return new class extends Migration
{
    public function up(): void
    {
        $locales = DB::table('locales')->pluck('code')->all() ?: ['en'];

        $attributeIds = [];

        $position = (int) DB::table('attributes')->max('position');

        foreach (GoogleAttributes::definitions() as $code => $definition) {
            $existing = DB::table('attributes')->where('code', $code)->value('id');

            $attributeIds[$code] = $existing ?: $this->createAttribute($code, $definition, ++$position, $locales);
        }

        foreach (DB::table('attribute_families')->pluck('id') as $familyId) {
            $groupId = DB::table('attribute_groups')
                ->where('attribute_family_id', $familyId)
                ->where('code', GoogleAttributes::GROUP_CODE)
                ->value('id');

            if (! $groupId) {
                $groupId = DB::table('attribute_groups')->insertGetId([
                    'code'                => GoogleAttributes::GROUP_CODE,
                    'attribute_family_id' => $familyId,
                    'name'                => GoogleAttributes::GROUP_NAME,
                    'column'              => 2,
                    'position'            => (int) DB::table('attribute_groups')->where('attribute_family_id', $familyId)->where('column', 2)->max('position') + 1,
                    'is_user_defined'     => 0,
                ]);
            }

            // An attribute may sit in only one group per family; skip ones already placed elsewhere.
            $placed = DB::table('attribute_group_mappings')
                ->join('attribute_groups', 'attribute_groups.id', '=', 'attribute_group_mappings.attribute_group_id')
                ->where('attribute_groups.attribute_family_id', $familyId)
                ->whereIn('attribute_group_mappings.attribute_id', $attributeIds)
                ->pluck('attribute_group_mappings.attribute_id')
                ->all();

            $i = 0;

            foreach ($attributeIds as $attributeId) {
                $i++;

                if (in_array($attributeId, $placed)) {
                    continue;
                }

                DB::table('attribute_group_mappings')->insert([
                    'attribute_id'       => $attributeId,
                    'attribute_group_id' => $groupId,
                    'position'           => $i,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('attribute_groups')->where('code', GoogleAttributes::GROUP_CODE)->delete();

        DB::table('attributes')
            ->whereIn('code', array_keys(GoogleAttributes::definitions()))
            ->where('code', '!=', 'gtin')
            ->delete();
    }

    protected function createAttribute(string $code, array $definition, int $position, array $locales): int
    {
        $now = now();

        $id = DB::table('attributes')->insertGetId([
            'code'                => $code,
            'admin_name'          => $definition['name'],
            'type'                => $definition['type'],
            'validation'          => $definition['validation'] ?? null,
            'position'            => $position,
            'is_required'         => 0,
            'is_unique'           => 0,
            'is_filterable'       => 0,
            'is_comparable'       => 0,
            'is_configurable'     => 0,
            'is_user_defined'     => 1,
            'is_visible_on_front' => 0,
            'value_per_locale'    => 0,
            'value_per_channel'   => 0,
            'default_value'       => $definition['default'] ?? null,
            'enable_wysiwyg'      => 0,
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);

        foreach ($locales as $locale) {
            DB::table('attribute_translations')->insert(['attribute_id' => $id, 'locale' => $locale, 'name' => $definition['name']]);
        }

        foreach ($definition['options'] ?? [] as $sort => $option) {
            $optionId = DB::table('attribute_options')->insertGetId([
                'attribute_id' => $id,
                'admin_name'   => $option,
                'sort_order'   => $sort + 1,
            ]);

            foreach ($locales as $locale) {
                DB::table('attribute_option_translations')->insert([
                    'attribute_option_id' => $optionId,
                    'locale'              => $locale,
                    'label'               => ucfirst($option),
                ]);
            }
        }

        return $id;
    }
};
