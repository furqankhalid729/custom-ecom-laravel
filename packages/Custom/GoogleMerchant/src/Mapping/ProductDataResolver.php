<?php

namespace Custom\GoogleMerchant\Mapping;

use Carbon\CarbonImmutable;
use Custom\GoogleMerchant\Support\StoreSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductAttributeValue;

/**
 * Every database read the mapper needs, for one channel + locale. Reads EAV
 * values directly so the result does not depend on the current request's
 * channel or locale (jobs have no request).
 */
class ProductDataResolver
{
    protected string $channelCode;

    protected int $channelId;

    protected string $locale;

    /** @var array<string, ?object> attribute code => definition */
    protected array $attributes = [];

    /** @var array<int, ?string> option id => label */
    protected array $optionLabels = [];

    protected ?int $guestGroupId = null;

    public function __construct(protected StoreSettings $store) {}

    public function forScope(int $channelId, string $locale): static
    {
        $this->channelId = $channelId;
        $this->channelCode = (string) DB::table('channels')->where('id', $channelId)->value('code');
        $this->locale = $locale;
        $this->attributes = [];
        $this->optionLabels = [];

        return $this;
    }

    /**
     * The product's own stored value (null when it has none).
     */
    public function own(Product $product, string $code): mixed
    {
        $attribute = $this->attribute($code);

        if (! $attribute) {
            return null;
        }

        $column = ProductAttributeValue::$attributeTypeFields[$attribute->type] ?? 'text_value';

        $rows = $product->attribute_values->where('attribute_id', $attribute->id);

        $row = $rows->first(fn ($row) => (! $attribute->value_per_channel || $row->channel === $this->channelCode)
                && (! $attribute->value_per_locale || $row->locale === $this->locale))
            ?? $rows->first();

        $value = $row?->{$column};

        return ($value === '' ? null : $value);
    }

    /**
     * Own value, else the attribute's default (e.g. gmc_sync defaults to 1 for products saved before it existed).
     */
    public function value(Product $product, string $code): mixed
    {
        return $this->own($product, $code) ?? $this->attribute($code)?->default_value;
    }

    public function bool(Product $product, string $code, bool $default): bool
    {
        $value = $this->value($product, $code);

        return $value === null ? $default : (bool) $value;
    }

    /** Label of a select option value, in the target locale. */
    public function label(Product $product, string $code): ?string
    {
        $attribute = $this->attribute($code);
        $value = $this->own($product, $code);

        if ($value === null) {
            return null;
        }

        if (! in_array($attribute->type, ['select', 'multiselect', 'checkbox'])) {
            return (string) $value;
        }

        $labels = array_filter(array_map(fn ($id) => $this->optionLabel((int) $id), explode(',', (string) $value)));

        return $labels ? implode(', ', $labels) : null;
    }

    /** Admin name of a select option (stable code, e.g. "refurbished"). */
    public function optionCode(Product $product, string $code): ?string
    {
        $value = $this->own($product, $code);

        if ($value === null) {
            return null;
        }

        // A select stores an option id; any other attribute type is used as written.
        if (($this->attribute($code)?->type) !== 'select') {
            return trim((string) $value) ?: null;
        }

        return DB::table('attribute_options')->where('id', (int) $value)->value('admin_name');
    }

    /** @return string[] absolute image URLs, by position */
    public function images(Product $product): array
    {
        return $product->images
            ->sortBy('position')
            ->map(fn ($image) => $this->assetUrl($image->path))
            ->values()
            ->all();
    }

    public function salableQty(Product $product): int
    {
        return (int) DB::table('product_inventory_indices')
            ->where('product_id', $product->id)
            ->where('channel_id', $this->channelId)
            ->value('qty');
    }

    /** True when every bundle option has at least one product in stock. */
    public function bundleInStock(Product $bundle): bool
    {
        $options = DB::table('product_bundle_options')->where('product_id', $bundle->id)->pluck('id');

        if ($options->isEmpty()) {
            return false;
        }

        foreach ($options as $optionId) {
            $inStock = DB::table('product_bundle_option_products as bop')
                ->join('product_inventory_indices as pii', function ($join) {
                    $join->on('pii.product_id', '=', 'bop.product_id')->where('pii.channel_id', '=', $this->channelId);
                })
                ->where('bop.product_bundle_option_id', $optionId)
                ->where('pii.qty', '>', 0)
                ->exists();

            if (! $inStock) {
                return false;
            }
        }

        return true;
    }

    /** Guest-group price index row (catalog rules + special prices applied). */
    public function priceIndex(Product $product): ?object
    {
        $this->guestGroupId ??= (int) DB::table('customer_groups')->where('code', 'guest')->value('id');

        return DB::table('product_price_indices')
            ->where('product_id', $product->id)
            ->where('channel_id', $this->channelId)
            ->where('customer_group_id', $this->guestGroupId)
            ->first();
    }

    /** @return Collection<int, object{id: int, path: string[]}> */
    public function categories(Product $product): Collection
    {
        return $product->categories()->get()->map(function ($category) {
            $chain = $category->ancestors()->whereNotNull('parent_id')->defaultOrder()->get()->push($category);

            return (object) [
                'id'   => $category->id,
                'path' => $chain->map(fn ($c) => $c->translate($this->locale)?->name ?? $c->name)->filter()->values()->all(),
            ];
        });
    }

    public function mappedGoogleCategory(array $categoryIds): ?string
    {
        if (! $categoryIds) {
            return null;
        }

        return DB::table('gmc_category_mappings')
            ->whereIn('category_id', $categoryIds)
            ->whereNotNull('google_product_category')
            ->orderBy('id')
            ->value('google_product_category');
    }

    public function websiteUrl(): string
    {
        return $this->store->websiteUrl($this->channelId);
    }

    public function weightUnit(): string
    {
        return core()->getConfigData('general.general.locale_options.weight_unit', $this->channelCode) === 'lbs' ? 'lb' : 'kg';
    }

    public function timezone(): string
    {
        return (string) (core()->getConfigData('general.locale_options.timezone', $this->channelCode) ?: config('app.timezone'));
    }

    public function date(?string $value, bool $endOfDay = false): ?CarbonImmutable
    {
        if (! $value) {
            return null;
        }

        $date = CarbonImmutable::parse(substr($value, 0, 10), $this->timezone());

        return $endOfDay ? $date->endOfDay() : $date->startOfDay();
    }

    /**
     * Public URL for a file on the public disk, served from the store's public website origin.
     */
    public function assetUrl(string $path): string
    {
        $url = Storage::disk('public')->url($path);

        $host = parse_url($url, PHP_URL_HOST);
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! $host || $host === $appHost || $host === 'localhost') {
            $relative = parse_url($url, PHP_URL_PATH) ?: '/storage/'.ltrim($path, '/');

            return $this->websiteUrl().'/'.ltrim($relative, '/');
        }

        return $url;
    }

    protected function attribute(string $code): ?object
    {
        if (! array_key_exists($code, $this->attributes)) {
            $this->attributes[$code] = DB::table('attributes')
                ->where('code', $code)
                ->first(['id', 'code', 'type', 'value_per_channel', 'value_per_locale', 'default_value']);
        }

        return $this->attributes[$code];
    }

    protected function optionLabel(int $optionId): ?string
    {
        if (! array_key_exists($optionId, $this->optionLabels)) {
            $this->optionLabels[$optionId] = DB::table('attribute_option_translations')
                ->where('attribute_option_id', $optionId)
                ->where('locale', $this->locale)
                ->value('label')
                ?: DB::table('attribute_options')->where('id', $optionId)->value('admin_name');
        }

        return $this->optionLabels[$optionId];
    }
}
