<?php

namespace Custom\StorePolicy\Services;

use Custom\StorePolicy\Enums\PageStatus;
use Custom\StorePolicy\Models\PolicyGenerationProxy;
use Custom\StorePolicy\Repositories\PolicySettingRepository;
use Webkul\CMS\Models\PageTranslationProxy;

class PolicyStatusService
{
    public function __construct(
        protected PolicySettingRepository $settingRepository,
        protected SettingsHasher $hasher,
    ) {}

    /**
     * Status of every policy page for the channel, keyed by template key.
     *
     * @return array<string, array{key: string, title: string, url_key: string, page_id: ?int, status: PageStatus, generated_at: ?\Carbon\Carbon, can_restore: bool}>
     */
    public function forChannel(int $channelId, string $locale = 'en'): array
    {
        $settings = $this->settingRepository->forChannel($channelId)->toSettingsArray();

        $statuses = [];

        foreach (config('store_policy.templates') as $key => $template) {
            $translation = $this->findTranslation($template['url_key'], $locale);

            $generation = $translation
                ? PolicyGenerationProxy::modelClass()::query()
                    ->where('cms_page_id', $translation->cms_page_id)
                    ->where('locale', $locale)
                    ->first()
                : null;

            $statuses[$key] = [
                'key'          => $key,
                'title'        => $template['title'],
                'url_key'      => $template['url_key'],
                'page_id'      => $translation?->cms_page_id,
                'status'       => $this->status($translation?->html_content, $generation, $settings, $template['depends']),
                'generated_at' => $generation?->generated_at,
                'can_restore'  => $generation !== null && $generation->previous_html !== null,
            ];
        }

        return $statuses;
    }

    /**
     * Template keys whose dependent settings changed since they were generated.
     */
    public function outOfDateKeys(int $channelId, string $locale = 'en'): array
    {
        return array_keys(array_filter(
            $this->forChannel($channelId, $locale),
            fn ($status) => $status['status']->isOutOfDate()
        ));
    }

    public function findTranslation(string $urlKey, string $locale = 'en')
    {
        return PageTranslationProxy::modelClass()::query()
            ->where('url_key', $urlKey)
            ->where('locale', $locale)
            ->first();
    }

    protected function status(?string $html, $generation, array $settings, array $depends): PageStatus
    {
        if (! $generation) {
            return PageStatus::NotGenerated;
        }

        $edited = $this->hasher->htmlHash($html) !== $generation->generated_html_hash;

        $outOfDate = $this->hasher->hash($settings, $depends) !== $generation->settings_hash;

        return match (true) {
            $edited && $outOfDate => PageStatus::OutOfDateEdited,
            $edited               => PageStatus::Edited,
            $outOfDate            => PageStatus::OutOfDate,
            default               => PageStatus::UpToDate,
        };
    }
}
