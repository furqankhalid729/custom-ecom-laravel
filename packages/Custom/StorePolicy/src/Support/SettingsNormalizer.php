<?php

namespace Custom\StorePolicy\Support;

/**
 * Turns admin form input (strings, "0"/"1", textarea lists) into the typed
 * settings shape, keeping only known keys.
 */
class SettingsNormalizer
{
    public const METHOD_SHAPE = [
        'name'      => 'string',
        'carrier'   => 'string',
        'cost'      => 'float',
        'free_over' => 'float',
        'min_days'  => 'int',
        'max_days'  => 'int',
    ];

    /**
     * @param  array  $input  Raw input.
     * @param  array  $template  Settings shape whose value types guide casting (Defaults::forChannel()).
     */
    public static function normalize(array $input, array $template, string $parent = ''): array
    {
        $result = [];

        foreach ($template as $key => $templateValue) {
            $value = $input[$key] ?? null;

            if ($parent === 'shipping' && $key === 'methods') {
                $result[$key] = self::methods($value);
            } elseif (is_array($templateValue) && ! array_is_list($templateValue)) {
                $result[$key] = self::normalize(is_array($value) ? $value : [], $templateValue, (string) $key);
            } elseif (is_array($templateValue)) {
                $result[$key] = self::list($value);
            } elseif (is_bool($templateValue)) {
                $result[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } elseif (is_int($templateValue)) {
                $result[$key] = self::int($value);
            } else {
                $result[$key] = self::string($value);
            }
        }

        return $result;
    }

    protected static function methods(mixed $rows): array
    {
        $methods = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $method = [];

            foreach (self::METHOD_SHAPE as $field => $type) {
                $value = $row[$field] ?? null;

                $method[$field] = match ($type) {
                    'float' => self::float($value),
                    'int'   => self::int($value),
                    default => self::string($value),
                };
            }

            // Skip the blank row the form always offers.
            if (count(array_filter($method, fn ($v) => $v !== null)) === 0) {
                continue;
            }

            $methods[] = $method;
        }

        return $methods;
    }

    protected static function list(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\r\n|\r|\n/', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($item) => is_scalar($item) ? trim((string) $item) : '',
            $value
        ), fn ($item) => $item !== ''));
    }

    /**
     * Numeric strings become ints; other non-empty values are kept for validation to reject.
     */
    protected static function int(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) && (int) $value == $value ? (int) $value : $value;
    }

    protected static function float(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : $value;
    }

    protected static function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
