<?php

use Custom\GoogleMerchant\Support\GoogleAttributes;
use Illuminate\Support\Facades\DB;

function googleShoppingMigration()
{
    return require base_path('packages/Custom/GoogleMerchant/src/Database/Migrations/2026_11_08_100010_add_google_shopping_attributes.php');
}

it('adds the google shopping group to every family', function () {
    foreach (DB::table('attribute_families')->pluck('id') as $familyId) {
        $group = DB::table('attribute_groups')
            ->where('attribute_family_id', $familyId)
            ->where('code', 'google_shopping')
            ->first();

        expect($group)->not->toBeNull();

        $codes = DB::table('attribute_group_mappings')
            ->join('attributes', 'attributes.id', '=', 'attribute_group_mappings.attribute_id')
            ->where('attribute_group_id', $group->id)
            ->pluck('attributes.code')
            ->all();

        expect($codes)->toContain('gmc_sync', 'gtin', 'gmc_identifier_exists', 'gmc_custom_label_4');
    }
});

it('creates select options for condition, gender and age group', function () {
    $options = fn (string $code) => DB::table('attribute_options')
        ->join('attributes', 'attributes.id', '=', 'attribute_options.attribute_id')
        ->where('attributes.code', $code)
        ->pluck('attribute_options.admin_name')
        ->all();

    expect($options('gmc_condition'))->toBe(['new', 'refurbished', 'used'])
        ->and($options('gmc_gender'))->toBe(['male', 'female', 'unisex'])
        ->and($options('gmc_age_group'))->toBe(['adult', 'kids', 'toddler', 'infant', 'newborn']);
});

it('defaults the sync and identifier flags to yes', function () {
    expect(DB::table('attributes')->where('code', 'gmc_sync')->value('default_value'))->toEqual(1)
        ->and(DB::table('attributes')->where('code', 'gmc_identifier_exists')->value('default_value'))->toEqual(1);
});

it('is idempotent', function () {
    googleShoppingMigration()->up();

    $codes = array_keys(GoogleAttributes::definitions());

    expect(DB::table('attributes')->whereIn('code', $codes)->count())->toBe(count($codes))
        ->and(DB::table('attribute_groups')->where('code', 'google_shopping')->count())
        ->toBe(DB::table('attribute_families')->count());

    $groupIds = DB::table('attribute_groups')->where('code', 'google_shopping')->pluck('id');

    expect(DB::table('attribute_group_mappings')->whereIn('attribute_group_id', $groupIds)->count())
        ->toBe(count($codes) * $groupIds->count());
});

it('shows the google shopping group on the product edit page', function () {
    $this->loginAsAdmin();

    $product = (new \Webkul\Faker\Helpers\Product)->getSimpleProductFactory()->create();

    \Pest\Laravel\get(route('admin.catalog.products.edit', $product->id))
        ->assertOk()
        ->assertSeeText('Google Shopping')
        ->assertSeeText('GTIN (UPC / EAN / ISBN)');
});
