<?php

namespace Custom\GoogleCore\Contracts;

/**
 * One Google service (Merchant Center, GA4, Ads, ...) living in its own package.
 */
interface GoogleIntegration
{
    public function key(): string;

    public function label(): string;

    public function isConnected(): bool;

    public function adminRoute(): ?string;
}
