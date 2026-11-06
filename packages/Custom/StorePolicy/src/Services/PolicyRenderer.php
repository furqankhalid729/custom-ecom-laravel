<?php

namespace Custom\StorePolicy\Services;

use Carbon\Carbon;
use Custom\StorePolicy\Support\Regions;
use InvalidArgumentException;

class PolicyRenderer
{
    /**
     * Render a policy template to CMS-safe HTML.
     *
     * The output is passed through Bagisto's clean_content() — the same cleaner the
     * CMS editor applies on save — so an unchanged re-save keeps the same HTML.
     */
    public function render(string $templateKey, array $settings, string $locale = 'en'): string
    {
        $template = config("store_policy.templates.{$templateKey}");

        if (! $template) {
            throw new InvalidArgumentException("Unknown policy template [{$templateKey}].");
        }

        $view = "store_policy::templates.{$locale}.{$templateKey}";

        if (! view()->exists($view)) {
            throw new InvalidArgumentException("No [{$locale}] template for [{$templateKey}].");
        }

        $html = view($view, [
            's'           => $settings,
            'regions'     => Regions::enabled($settings),
            'template'    => $template,
            'lastUpdated'   => Carbon::today()->format('F j, Y'),
            'effectiveDate' => $this->effectiveDate($settings),
        ])->render();

        return clean_content($this->collapseWhitespace($html));
    }

    protected function effectiveDate(array $settings): ?string
    {
        $date = data_get($settings, 'terms.effective_date');

        return $date ? Carbon::parse($date)->format('F j, Y') : null;
    }

    protected function collapseWhitespace(string $html): string
    {
        $html = preg_replace('/>\s+</', '><', trim($html));

        return preg_replace('/\s{2,}/', ' ', $html);
    }
}
