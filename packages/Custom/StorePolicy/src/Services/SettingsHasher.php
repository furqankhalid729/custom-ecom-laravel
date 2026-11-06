<?php

namespace Custom\StorePolicy\Services;

class SettingsHasher
{
    /**
     * Hash of the settings values at the given paths (data_get syntax, * allowed).
     */
    public function hash(array $settings, array $paths): string
    {
        $values = [];

        foreach ($paths as $path) {
            $values[$path] = data_get($settings, $path);
        }

        return sha1(json_encode($this->sortRecursive($values)));
    }

    /**
     * Hash of page HTML, ignoring differences the CMS editor (TinyMCE) introduces
     * on an unchanged save: whitespace between tags and absolute own-site URLs.
     */
    public function htmlHash(?string $html): string
    {
        return sha1($this->normalizeHtml((string) $html));
    }

    public function normalizeHtml(string $html): string
    {
        $html = trim($html);

        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        if ($host) {
            $html = preg_replace('#\b(href|src)="https?://'.preg_quote($host, '#').'(?::\d+)?/#i', '$1="/', $html);
        }

        $html = preg_replace('/>\s+</', '><', $html);

        return preg_replace('/\s+/', ' ', $html);
    }

    protected function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => $this->sortRecursive($item), $value);
    }
}
