<?php

namespace Custom\GoogleMerchant\Mapping;

use Custom\GoogleMerchant\Models\GmcTarget;
use Custom\GoogleMerchant\Support\Micros;
use Custom\GoogleMerchant\Support\Settings;
use Custom\GoogleMerchant\Support\Text;
use Webkul\Product\Models\Product;

/**
 * Store product → Merchant API ProductInput(s).
 *
 * simple / virtual / downloadable / booking → 1 offer; bundle → 1 offer (isBundle);
 * configurable → 1 offer per variant grouped by itemGroupId (parent not sent);
 * grouped → nothing (its children are their own roots).
 */
class ProductMapper
{
    public const TITLE_LIMIT = 150;

    public const DESCRIPTION_LIMIT = 5000;

    public const MAX_ADDITIONAL_IMAGES = 10;

    /** Bagisto names generated variants "Variant <option ids>". */
    protected const AUTO_VARIANT_NAME = '/^Variant(\s+\d+)+$/';

    protected GmcTarget $target;

    public function __construct(
        protected ProductDataResolver $data,
        protected MappingRegistry $mapping,
        protected Settings $settings,
    ) {}

    /** @return MappedOffer[] */
    public function map(Product $root, GmcTarget $target): array
    {
        $this->target = $target;
        $this->data->forScope($target->channel_id, $target->locale);

        return match ($root->type) {
            'grouped'      => [],
            'configurable' => $root->variants()->orderBy('id')->get()
                ->map(fn (Product $variant) => $this->offer($variant, $root))
                ->all(),
            default        => [$this->offer($root, null)],
        };
    }

    protected function offer(Product $product, ?Product $parent): MappedOffer
    {
        $root = $parent ?? $product;

        $attributes = array_merge(
            $this->descriptive($product, $parent),
            $this->media($product, $parent),
            $this->commercial($product),
            $this->identifiers($product, $parent),
            $this->classification($product, $parent),
        );

        if ($parent) {
            $attributes['itemGroupId'] = $parent->sku;
        }

        if ($product->type === 'bundle') {
            $attributes['isBundle'] = true;
        }

        $input = [
            'offerId'           => (string) $product->sku,
            'contentLanguage'   => $this->target->content_language,
            'feedLabel'         => $this->target->feed_label,
            'productAttributes' => $this->clean($attributes),
        ];

        return new MappedOffer($product->id, $root->id, (string) $product->sku, $parent?->sku, $input);
    }

    protected function descriptive(Product $product, ?Product $parent): array
    {
        $name = $this->attr($product, 'title');

        if ($parent && ($name === null || preg_match(self::AUTO_VARIANT_NAME, $name))) {
            $labels = array_filter(array_map(fn ($code) => $this->data->label($product, $code), $this->superAttributeCodes($parent)));

            $name = trim($this->attr($parent, 'title').($labels ? ' – '.implode(' / ', $labels) : ''));
        }

        $description = $this->attr($product, 'description');

        if ($parent && ($description === null || trim(strip_tags($description)) === $product->sku)) {
            $description = $this->attr($parent, 'description');
        }

        $description ??= $this->data->own($parent ?? $product, 'short_description');

        $urlKey = $this->data->own($parent ?? $product, 'url_key');

        $link = $urlKey ? $this->data->websiteUrl().'/'.$urlKey : null;

        if ($link && $parent) {
            $link .= '?variant='.rawurlencode($product->sku);
        }

        return [
            'title'       => Text::plain($name, self::TITLE_LIMIT),
            'description' => Text::plain($description, self::DESCRIPTION_LIMIT),
            'link'        => $link,
        ];
    }

    protected function media(Product $product, ?Product $parent): array
    {
        $images = array_values(array_unique(array_merge(
            $this->data->images($product),
            $parent ? $this->data->images($parent) : [],
        )));

        $weight = (float) ($this->data->own($product, 'weight') ?? ($parent ? $this->data->own($parent, 'weight') : 0));

        return [
            'imageLink'            => $images[0] ?? null,
            'additionalImageLinks' => array_slice($images, 1, self::MAX_ADDITIONAL_IMAGES),
            'shippingWeight'       => $weight > 0 ? ['value' => $weight, 'unit' => $this->data->weightUnit()] : null,
        ];
    }

    protected function commercial(Product $product): array
    {
        $currency = $this->target->currency;

        $index = $this->data->priceIndex($product);

        if ($product->type === 'bundle') {
            $regular = (float) ($index->regular_min_price ?? 0);
            $sale = isset($index->min_price) && (float) $index->min_price < $regular ? (float) $index->min_price : null;

            return [
                'price'        => $regular > 0 ? Micros::price($regular, $currency) : null,
                'salePrice'    => $sale ? Micros::price($sale, $currency) : null,
                'availability' => $this->data->bundleInStock($product) ? 'IN_STOCK' : 'OUT_OF_STOCK',
            ];
        }

        $price = (float) $this->data->own($product, 'price');

        $sale = null;
        $saleWindow = null;

        $special = $this->data->own($product, 'special_price');

        if ($special !== null && (float) $special > 0 && (float) $special < $price && $this->specialActive($product)) {
            $sale = (float) $special;

            $from = $this->data->date($this->data->own($product, 'special_price_from'));
            $to = $this->data->date($this->data->own($product, 'special_price_to'), true);

            if ($from || $to) {
                $saleWindow = [
                    'startTime' => ($from ?? now($this->data->timezone())->startOfDay())->toRfc3339String(),
                    'endTime'   => ($to ?? ($from ?? now())->addYear())->toRfc3339String(),
                ];
            }
        } elseif ($index && (float) $index->min_price > 0 && (float) $index->min_price < $price) {
            // Catalog-rule price: no dates (the nightly refresh resubmits when it changes).
            $sale = (float) $index->min_price;
        }

        $manageStock = $this->data->bool($product, 'manage_stock', true);

        return [
            'price'                  => $price > 0 ? Micros::price($price, $currency) : null,
            'salePrice'              => $sale ? Micros::price($sale, $currency) : null,
            'salePriceEffectiveDate' => $saleWindow,
            'availability'           => ! $manageStock || $this->data->salableQty($product) > 0 ? 'IN_STOCK' : 'OUT_OF_STOCK',
        ];
    }

    protected function identifiers(Product $product, ?Product $parent): array
    {
        $brand = $this->labelAttr($product, 'brand') ?? ($parent ? $this->labelAttr($parent, 'brand') : null);
        $brand ??= trim((string) $this->settings->get('settings.default_brand')) ?: null;

        // GTIN / MPN identify one exact item: never inherited from the parent.
        $gtin = $this->attr($product, 'gtin');
        $mpn = $this->attr($product, 'mpn');

        $source = $this->mapping->sourceFor('identifierExists', $this->target)['value'];

        // Only an explicit "No" from the admin is sent; the variant inherits the parent's explicit choice.
        $identifierExists = $this->data->own($product, $source) ?? ($parent ? $this->data->own($parent, $source) : null);

        return [
            'brand'            => $brand,
            'gtins'            => $gtin !== null ? [preg_replace('/\s+/', '', (string) $gtin)] : null,
            'mpn'              => $mpn,
            'identifierExists' => $identifierExists !== null && ! (bool) $identifierExists ? false : null,
        ];
    }

    protected function classification(Product $product, ?Product $parent): array
    {
        $inherit = fn (string $field) => $this->attr($product, $field) ?? ($parent ? $this->attr($parent, $field) : null);

        $enum = function (string $field) use ($product, $parent) {
            $code = $this->mapping->sourceFor($field, $this->target)['value'];
            $value = $this->data->optionCode($product, $code) ?? ($parent ? $this->data->optionCode($parent, $code) : null);

            $value ??= $this->mapping->fallbackFor($field);

            return $value ? strtoupper($value) : null;
        };

        $categories = $this->data->categories($parent ?? $product);

        $googleCategory = $inherit('googleProductCategory')
            ?? $this->data->mappedGoogleCategory($categories->pluck('id')->all());

        $attributes = [
            'condition'             => $enum('condition'),
            'gender'                => $enum('gender'),
            'ageGroup'              => $enum('ageGroup'),
            'googleProductCategory' => $googleCategory,
            'productTypes'          => $categories->map(fn ($c) => implode(' > ', $c->path))->filter()->unique()->take(10)->values()->all(),
            'color'                 => $this->labelAttr($product, 'color'),
            'size'                  => $this->labelAttr($product, 'size'),
            'material'              => $inherit('material'),
            'pattern'               => $inherit('pattern'),
            'multipack'             => ($m = $inherit('multipack')) !== null && (int) $m > 1 ? (string) (int) $m : null,
        ];

        foreach (range(0, 4) as $i) {
            $attributes["customLabel$i"] = $inherit("customLabel$i");
        }

        return $attributes;
    }

    protected function specialActive(Product $product): bool
    {
        $today = now($this->data->timezone())->startOfDay();

        $from = $this->data->date($this->data->own($product, 'special_price_from'));
        $to = $this->data->date($this->data->own($product, 'special_price_to'));

        return (! $from || $from->lte($today)) && (! $to || $to->gte($today));
    }

    /** Raw value of the store attribute mapped to a Google field. */
    protected function attr(Product $product, string $googleField): ?string
    {
        $source = $this->mapping->sourceFor($googleField, $this->target);

        if ($source['type'] !== 'attribute' || ! $source['value']) {
            return $source['type'] === 'static' ? $source['value'] : null;
        }

        $value = $this->data->own($product, $source['value']);

        return $value === null ? null : trim((string) $value);
    }

    protected function labelAttr(Product $product, string $googleField): ?string
    {
        $code = $this->mapping->sourceFor($googleField, $this->target)['value'];

        return $code ? $this->data->label($product, $code) : null;
    }

    /** @return string[] */
    protected function superAttributeCodes(Product $parent): array
    {
        return $parent->super_attributes()->orderBy('attributes.id')->pluck('code')->all();
    }

    /** Drops empty values but keeps explicit booleans (identifierExists=false). */
    protected function clean(array $attributes): array
    {
        return array_filter($attributes, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }
}
