<?php

namespace Custom\StorePolicy\Services;

use Custom\StorePolicy\Enums\PageStatus;
use Custom\StorePolicy\Repositories\PolicySettingRepository;
use Custom\StorePolicy\Support\Regions;
use Custom\StorePolicy\Support\RequiredFields;
use Webkul\CMS\Models\PageProxy;
use Webkul\CMS\Models\PageTranslationProxy;

/**
 * Checklist of what Google Merchant Center looks for on the website.
 */
class ReadinessChecker
{
    public function __construct(
        protected PolicySettingRepository $settingRepository,
        protected PolicyStatusService $statusService,
        protected FooterLinkSync $footerLinkSync,
    ) {}

    /**
     * @return array<int, array{key: string, label: string, passed: bool, detail: ?string}>
     */
    public function check(int $channelId): array
    {
        $settings = $this->settingRepository->forChannel($channelId)->toSettingsArray();
        $statuses = $this->statusService->forChannel($channelId);

        $missingBusiness = array_values(array_filter(
            RequiredFields::missing($settings),
            fn ($path) => str_starts_with($path, 'business.')
        ));

        $notGenerated = array_keys(array_filter(
            array_intersect_key($statuses, array_flip(['privacy-policy', 'terms-conditions', 'payment-policy'])),
            fn ($status) => $status['status'] === PageStatus::NotGenerated
        ));

        $missingLinks = $this->footerLinkSync->missingLinks($channelId, array_keys(config('store_policy.templates')));

        $regionsWithoutShipping = array_keys(array_filter(
            Regions::enabled($settings),
            fn ($region) => ! $this->hasCompleteShippingMethod($region)
        ));

        $placeholderLocales = $this->placeholderLocales($statuses);

        $structuredData = core()->getConfigData('catalog.rich_snippets.products.enable')
            && core()->getConfigData('catalog.rich_snippets.products.show_offers');

        return [
            $this->item('business_contact', ! $missingBusiness, $missingBusiness ? implode(', ', $missingBusiness) : null),
            $this->item('return_policy', $this->isPublished($statuses['return-policy'], $channelId)),
            $this->item(
                'shipping_policy',
                $this->isPublished($statuses['shipping-policy'], $channelId) && ! $regionsWithoutShipping,
                $regionsWithoutShipping ? implode(', ', array_map([Regions::class, 'label'], $regionsWithoutShipping)) : null
            ),
            $this->item('other_pages', ! $notGenerated, $notGenerated ? implode(', ', $notGenerated) : null),
            $this->item('footer_links', ! $missingLinks, $missingLinks ? implode(', ', $missingLinks) : null),
            $this->item('structured_data', (bool) $structuredData),
            $this->item('other_locales', ! $placeholderLocales, $placeholderLocales ? implode(', ', $placeholderLocales) : null),
        ];
    }

    protected function item(string $key, bool $passed, ?string $detail = null): array
    {
        return [
            'key'    => $key,
            'label'  => trans("store_policy::app.readiness.{$key}.label"),
            'passed' => $passed,
            'detail' => $passed ? null : trim(trans("store_policy::app.readiness.{$key}.fail").($detail ? ' '.$detail : '')),
        ];
    }

    protected function isPublished(array $status, int $channelId): bool
    {
        if (! in_array($status['status'], [PageStatus::UpToDate, PageStatus::Edited], true) || ! $status['page_id']) {
            return false;
        }

        return PageProxy::modelClass()::query()
            ->whereKey($status['page_id'])
            ->whereHas('channels', fn ($query) => $query->where('channels.id', $channelId))
            ->exists();
    }

    protected function hasCompleteShippingMethod(array $region): bool
    {
        foreach ($region['shipping']['methods'] ?? [] as $method) {
            if (isset($method['cost']) && $method['cost'] !== '' && ! empty($method['max_days'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Locales (other than en) whose copy of a generated page differs from the English text.
     */
    protected function placeholderLocales(array $statuses): array
    {
        $locales = [];

        foreach ($statuses as $status) {
            if ($status['status'] === PageStatus::NotGenerated || ! $status['page_id']) {
                continue;
            }

            $translations = PageTranslationProxy::modelClass()::query()
                ->where('cms_page_id', $status['page_id'])
                ->get(['locale', 'html_content']);

            $english = $translations->firstWhere('locale', 'en')?->html_content;

            foreach ($translations as $translation) {
                if ($translation->locale !== 'en' && $translation->html_content !== $english) {
                    $locales[$translation->locale] = $translation->locale;
                }
            }
        }

        return array_values($locales);
    }
}
