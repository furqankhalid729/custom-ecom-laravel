<?php

namespace Custom\GoogleMerchant\Support;

/**
 * Google's product taxonomy ("<id> - A > B > C" per line), read from the file
 * downloaded by `php artisan gmc:taxonomy`. Without the file, categories can
 * still be mapped by typing the numeric ID.
 */
class Taxonomy
{
    public const SOURCE_URL = 'https://www.google.com/basepages/producttype/taxonomy-with-ids.en-US.txt';

    /** @var array<string, string>|null id => path */
    protected ?array $entries = null;

    public function path(): string
    {
        return (string) config('google_merchant.taxonomy_path');
    }

    public function available(): bool
    {
        return is_file($this->path());
    }

    /** @return array<int, array{id: string, path: string}> */
    public function search(string $query, int $limit = 20): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $entries = $this->entries();

        if (ctype_digit($query)) {
            return isset($entries[$query]) ? [['id' => $query, 'path' => $entries[$query]]] : [];
        }

        $needle = mb_strtolower($query);
        $results = [];

        foreach ($entries as $id => $path) {
            if (str_contains(mb_strtolower($path), $needle)) {
                $results[] = ['id' => (string) $id, 'path' => $path];

                if (count($results) >= $limit) {
                    break;
                }
            }
        }

        return $results;
    }

    public function pathFor(?string $id): ?string
    {
        return $id === null ? null : ($this->entries()[$id] ?? null);
    }

    /** @return array<string, string> */
    protected function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $this->entries = [];

        if (! $this->available()) {
            return $this->entries;
        }

        foreach (file($this->path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (preg_match('/^(\d+)\s+-\s+(.+)$/', trim($line), $m)) {
                $this->entries[$m[1]] = $m[2];
            }
        }

        return $this->entries;
    }
}
