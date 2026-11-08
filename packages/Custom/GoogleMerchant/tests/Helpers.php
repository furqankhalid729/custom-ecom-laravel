<?php

use Custom\GoogleMerchant\Models\GmcAccount;
use Custom\GoogleMerchant\Models\GmcTarget;
use Illuminate\Support\Facades\DB;
use Webkul\Faker\Helpers\Product as ProductFaker;
use Webkul\Product\Helpers\Indexers\Price as PriceIndexer;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductAttributeValue;
use Webkul\Product\Repositories\ProductRepository;

if (! function_exists('fixture')) {
    /**
     * Decoded JSON fixture from tests/Fixtures (recorded from the Merchant API v1 reference).
     */
    function fixture(string $name): array
    {
        return json_decode(file_get_contents(__DIR__.'/Fixtures/'.$name.'.json'), true);
    }

    /**
     * Connected service-account account for merchant 123456 with a token valid for an hour.
     */
    function gmcAccount(array $overrides = []): GmcAccount
    {
        return GmcAccount::create(array_merge([
            'merchant_id'          => '123456',
            'display_name'         => 'Acme Store',
            'auth_type'            => 'service_account',
            'google_email'         => 'sync@project.iam.gserviceaccount.com',
            'access_token'         => 'test-token',
            'token_expires_at'     => now()->addHour(),
            'service_account_json' => json_encode(['type' => 'service_account', 'client_email' => 'sync@project.iam.gserviceaccount.com', 'private_key' => 'unused']),
            'status'               => 'connected',
            'developer_registered_at' => now(),
        ], $overrides));
    }

    /**
     * US / en / USD target with a provisioned API data source.
     */
    function gmcTarget(GmcAccount $account, array $overrides = []): GmcTarget
    {
        return GmcTarget::create(array_merge([
            'gmc_account_id'   => $account->id,
            'channel_id'       => core()->getDefaultChannel()->id,
            'locale'           => 'en',
            'content_language' => 'en',
            'feed_label'       => 'US',
            'countries'        => ['US'],
            'currency'         => 'USD',
            'data_source_name' => 'accounts/123456/dataSources/999',
            'enabled'          => true,
        ], $overrides));
    }

    /**
     * Store admin configuration values (Configure → Google Merchant Center).
     *
     * @param  array<string, mixed>  $values  keys relative to google_merchant.general., e.g. 'settings.enabled'
     */
    function gmcConfig(array $values): void
    {
        foreach ($values as $key => $value) {
            DB::table('core_config')->updateOrInsert(
                ['code' => 'google_merchant.general.'.$key],
                ['value' => is_array($value) ? implode(',', $value) : (string) $value, 'channel_code' => null, 'locale_code' => null, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        // Bagisto caches CoreConfigRepository lookups; admin saves clear it, raw inserts don't.
        \Illuminate\Support\Facades\Cache::flush();
    }

    /**
     * Writes one EAV value the way Bagisto stores it (channel/locale only when the attribute varies by them).
     */
    function setProductAttribute(Product $product, string $code, mixed $value): void
    {
        $attribute = DB::table('attributes')->where('code', $code)->first();

        $column = ProductAttributeValue::$attributeTypeFields[$attribute->type];

        $channel = $attribute->value_per_channel ? core()->getDefaultChannelCode() : null;
        $locale = $attribute->value_per_locale ? 'en' : null;

        DB::table('product_attribute_values')->updateOrInsert(
            ['product_id' => $product->id, 'attribute_id' => $attribute->id, 'channel' => $channel, 'locale' => $locale],
            [
                $column     => $value,
                'unique_id' => implode('|', array_filter([$channel, $locale, $product->id, $attribute->id])),
            ]
        );

        $product->unsetRelation('attribute_values');
    }

    function optionId(string $attributeCode, string $adminName): int
    {
        $attributeId = DB::table('attributes')->where('code', $attributeCode)->value('id');

        return DB::table('attribute_options')->where('attribute_id', $attributeId)->where('admin_name', $adminName)->value('id')
            ?? tap(DB::table('attribute_options')->insertGetId(['attribute_id' => $attributeId, 'admin_name' => $adminName, 'sort_order' => 99]), function ($id) use ($adminName) {
                DB::table('attribute_option_translations')->insert(['attribute_option_id' => $id, 'locale' => 'en', 'label' => $adminName]);
            });
    }

    function setStock(Product $product, int $qty): void
    {
        DB::table('product_inventory_indices')->updateOrInsert(
            ['product_id' => $product->id, 'channel_id' => core()->getDefaultChannel()->id],
            ['qty' => $qty, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    function addImage(Product $product, string $file = 'main.webp', int $position = 1): void
    {
        DB::table('product_images')->insert([
            'type' => 'image', 'path' => "product/{$product->id}/{$file}", 'product_id' => $product->id, 'position' => $position,
        ]);

        $product->unsetRelation('images');
    }

    function reindexPrices(Product ...$products): void
    {
        app(PriceIndexer::class)->reindexBatch(array_map(fn ($p) => $p->fresh(), $products));
    }

    /**
     * A sellable simple product with brand, GTIN, image, stock 10 and price 21.
     */
    function simpleProduct(array $attributes = []): Product
    {
        $product = (new ProductFaker)->getSimpleProductFactory()->create();

        $defaults = [
            'name'        => 'Arctic Cozy Knit Beanie',
            'url_key'     => 'arctic-cozy-beanie-'.$product->id,
            'description' => '<p>Warm <strong>knit</strong> beanie &amp; soft lining.</p>',
            'price'       => 21,
            'special_price' => null,
            'status'      => 1,
            'visible_individually' => 1,
            'manage_stock' => 1,
            'brand'       => optionId('brand', 'Arctic'),
            'gtin'        => '4006381333931',
            'product_number' => 'MPN-'.$product->id,
        ];

        foreach (array_merge($defaults, $attributes) as $code => $value) {
            setProductAttribute($product, $code, $value);
        }

        addImage($product);
        setStock($product, 10);
        reindexPrices($product);

        return $product->fresh();
    }

    /**
     * Configurable parent with one variant per size (S/M/L) in colour Red,
     * priced 14 / 17 / 21. Variants keep Bagisto's auto-generated names.
     */
    function configurableProduct(int $variants = 3): Product
    {
        $sizes = array_slice(['S', 'M', 'L', 'XL'], 0, $variants);

        $parent = app(ProductRepository::class)->create([
            'type'                => 'configurable',
            'attribute_family_id' => 1,
            'sku'                 => 'cfg-'.uniqid(),
            'super_attributes'    => [
                'color' => [optionId('color', 'Red')],
                'size'  => array_map(fn ($s) => optionId('size', $s), $sizes),
            ],
        ]);

        foreach ([
            'name' => 'Puffer Jacket', 'url_key' => 'puffer-jacket-'.$parent->id, 'description' => '<p>Warm puffer jacket.</p>',
            'status' => 1, 'visible_individually' => 1, 'brand' => optionId('brand', 'OmniHeat'),
        ] as $code => $value) {
            setProductAttribute($parent, $code, $value);
        }

        addImage($parent, 'parent.webp');

        $prices = [14, 17, 21, 25];

        foreach ($parent->fresh()->variants()->orderBy('id')->get() as $i => $variant) {
            setProductAttribute($variant, 'price', $prices[$i]);
            setProductAttribute($variant, 'gtin', ['4006381333931', '012345678905', '96385074', '5901234123457'][$i]);
            setProductAttribute($variant, 'visible_individually', 0);
            setStock($variant, 5);
            reindexPrices($variant);
        }

        reindexPrices($parent);

        return $parent->fresh();
    }
}
