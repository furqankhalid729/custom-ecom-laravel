<?php

namespace Custom\GoogleMerchant\Support;

/**
 * Typed access to Configure → Google Merchant Center values.
 */
class Settings
{
    public const PREFIX = 'google_merchant.general.';

    public function get(string $key, mixed $default = null): mixed
    {
        $value = core()->getConfigData(self::PREFIX.$key);

        return ($value === null || $value === '') ? $default : $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        return filter_var($this->get($key, $default), FILTER_VALIDATE_BOOLEAN);
    }

    public function int(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    /** @return string[] */
    public function list(string $key): array
    {
        $value = $this->get($key, []);

        return array_values(array_filter(is_array($value) ? $value : explode(',', (string) $value)));
    }

    public function enabled(): bool
    {
        return $this->bool('settings.enabled');
    }
}
