<?php

namespace Custom\GoogleMerchant\Mapping;

/**
 * One Google offer built from a store product (a simple, a bundle, or one variant).
 */
final class MappedOffer
{
    public readonly string $hash;

    /** @var array<string, string> Google field => sha1 of its value (used to detect price/stock-only changes) */
    public readonly array $fieldHashes;

    public function __construct(
        public readonly int $productId,
        public readonly int $rootProductId,
        public readonly string $offerId,
        public readonly ?string $itemGroupId,
        public readonly array $productInput,
    ) {
        $this->hash = sha1(json_encode(self::sortKeys($productInput)));

        $this->fieldHashes = array_map(
            fn ($value) => sha1(json_encode(is_array($value) ? self::sortKeys($value) : $value)),
            $productInput['productAttributes'] ?? [],
        );
    }

    /**
     * Fields whose value differs from $previous (added, changed or removed).
     *
     * @param  array<string, string>  $previous
     * @return string[]
     */
    public function changedFields(array $previous): array
    {
        $fields = array_unique(array_merge(array_keys($previous), array_keys($this->fieldHashes)));

        return array_values(array_filter($fields, fn ($f) => ($previous[$f] ?? null) !== ($this->fieldHashes[$f] ?? null)));
    }

    public function attributes(): array
    {
        return $this->productInput['productAttributes'] ?? [];
    }

    protected static function sortKeys(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortKeys($item);
            }
        }

        return $value;
    }
}
