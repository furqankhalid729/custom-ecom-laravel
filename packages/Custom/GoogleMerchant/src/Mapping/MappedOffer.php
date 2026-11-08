<?php

namespace Custom\GoogleMerchant\Mapping;

/**
 * One Google offer built from a store product (a simple, a bundle, or one variant).
 */
final class MappedOffer
{
    public readonly string $hash;

    public function __construct(
        public readonly int $productId,
        public readonly int $rootProductId,
        public readonly string $offerId,
        public readonly ?string $itemGroupId,
        public readonly array $productInput,
    ) {
        $this->hash = sha1(json_encode(self::sortKeys($productInput)));
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
