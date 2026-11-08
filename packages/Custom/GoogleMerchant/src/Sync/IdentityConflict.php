<?php

namespace Custom\GoogleMerchant\Sync;

/**
 * The Google identity (target + offerId) already belongs to another live store product.
 */
final class IdentityConflict
{
    public function __construct(
        public readonly string $offerId,
        public readonly int $ownerProductId,
    ) {}
}
