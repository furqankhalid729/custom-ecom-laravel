<?php

namespace Custom\GoogleMerchant\Console\Commands;

use Custom\GoogleMerchant\Support\Taxonomy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class TaxonomyCommand extends Command
{
    protected $signature = 'gmc:taxonomy {--from= : Import a local taxonomy-with-ids file instead of downloading it}';

    protected $description = "Download Google's product taxonomy (used by the category mapping search)";

    public function handle(Taxonomy $taxonomy): int
    {
        if ($from = $this->option('from')) {
            $content = is_file($from) ? file_get_contents($from) : false;
        } else {
            $response = Http::timeout(30)->get(Taxonomy::SOURCE_URL);
            $content = $response->successful() ? $response->body() : false;
        }

        if ($content === false || ! preg_match('/^\d+ - /m', (string) $content)) {
            $this->error('Could not read a Google taxonomy file (expected lines like "1604 - Apparel & Accessories > Clothing").');

            return self::FAILURE;
        }

        File::ensureDirectoryExists(dirname($taxonomy->path()));
        File::put($taxonomy->path(), $content);

        $this->info('Saved '.substr_count($content, "\n").' lines to '.$taxonomy->path());

        return self::SUCCESS;
    }
}
