<?php

namespace Custom\StorePolicy\Services;

use Custom\StorePolicy\Exceptions\MissingSettingsException;
use Custom\StorePolicy\Models\PolicyGenerationProxy;
use Custom\StorePolicy\Repositories\PolicySettingRepository;
use Custom\StorePolicy\Support\RequiredFields;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Webkul\CMS\Repositories\PageRepository;

class PolicyGenerator
{
    public const LOCALE = 'en';

    public function __construct(
        protected PolicySettingRepository $settingRepository,
        protected PolicyRenderer $renderer,
        protected SettingsHasher $hasher,
        protected PolicyStatusService $statusService,
        protected PageRepository $pageRepository,
        protected FooterLinkSync $footerLinkSync,
    ) {}

    /**
     * Generate the given policy pages from the channel's settings.
     *
     * Pages edited by hand are skipped unless their key is in $confirmOverwrite.
     *
     * @return array{generated: string[], skipped: string[]}
     *
     * @throws MissingSettingsException
     */
    public function generate(int $channelId, array $templateKeys, array $confirmOverwrite = [], ?int $adminId = null): array
    {
        $settingsModel = $this->settingRepository->forChannel($channelId);
        $settings = $settingsModel->toSettingsArray();

        if ($missing = RequiredFields::missing($settings)) {
            throw new MissingSettingsException($missing);
        }

        $templates = array_intersect_key(config('store_policy.templates'), array_flip($templateKeys));

        return DB::transaction(function () use ($channelId, $settings, $templates, $confirmOverwrite, $adminId) {
            if (empty($settings['terms']['effective_date'])) {
                $settings['terms']['effective_date'] = now()->format('Y-m-d');

                $this->settingRepository->saveForChannel($channelId, $settings);
            }

            $statuses = $this->statusService->forChannel($channelId, self::LOCALE);

            $result = ['generated' => [], 'skipped' => []];

            foreach ($templates as $key => $template) {
                if ($statuses[$key]['status']->needsConfirmation() && ! in_array($key, $confirmOverwrite, true)) {
                    $result['skipped'][] = $key;

                    continue;
                }

                $this->generatePage($channelId, $key, $template, $settings, $adminId);

                $result['generated'][] = $key;
            }

            if ($result['generated']) {
                $this->footerLinkSync->ensureLinks($channelId, $result['generated']);
            }

            return $result;
        });
    }

    /**
     * Put back the page HTML that the last generation replaced.
     */
    public function restore(int $channelId, string $templateKey): void
    {
        $template = config("store_policy.templates.{$templateKey}") ?? throw new RuntimeException("Unknown policy template [{$templateKey}].");

        $translation = $this->statusService->findTranslation($template['url_key'], self::LOCALE);

        $generation = $translation
            ? PolicyGenerationProxy::modelClass()::query()
                ->where('cms_page_id', $translation->cms_page_id)
                ->where('locale', self::LOCALE)
                ->first()
            : null;

        if (! $generation || $generation->previous_html === null) {
            throw new RuntimeException("Nothing to restore for [{$templateKey}].");
        }

        DB::transaction(function () use ($translation, $generation) {
            // Swap, so the restore itself can be undone and no text is lost.
            $current = $translation->html_content;

            $translation->html_content = $generation->previous_html;
            $translation->save();

            $generation->previous_html = $current;
            $generation->save();

            Event::dispatch('cms.page.update.after', $this->pageRepository->find($translation->cms_page_id));
        });
    }

    /**
     * The HTML worth keeping for restore: the page's current text if someone wrote it,
     * or — when the page still holds our own last output — the version kept before.
     */
    protected function previousHtmlToKeep(int $pageId, ?string $currentHtml): ?string
    {
        $generation = PolicyGenerationProxy::modelClass()::query()
            ->where('cms_page_id', $pageId)
            ->where('locale', self::LOCALE)
            ->first();

        if ($generation && $this->hasher->htmlHash($currentHtml) === $generation->generated_html_hash) {
            return $generation->previous_html;
        }

        return $currentHtml;
    }

    protected function generatePage(int $channelId, string $key, array $template, array $settings, ?int $adminId): void
    {
        $html = $this->renderer->render($key, $settings, self::LOCALE);

        $attributes = [
            'url_key'          => $template['url_key'],
            'page_title'       => $template['title'],
            'html_content'     => $html,
            'meta_title'       => $template['title'],
            'meta_description' => $template['meta_description'],
        ];

        $translation = $this->statusService->findTranslation($template['url_key'], self::LOCALE);

        if ($translation) {
            $pageId = $translation->cms_page_id;
            $previousHtml = $this->previousHtmlToKeep($pageId, $translation->html_content);

            $channels = $this->pageRepository->find($pageId)->channels->pluck('id')->push($channelId)->unique()->values()->all();

            // 'cms.page.update.before' is not dispatched: its only listener (Marketing URL
            // rewrites) reads the admin form request, and generation never changes url_key.
            $page = $this->pageRepository->update([
                self::LOCALE => $attributes + ['meta_keywords' => $translation->meta_keywords],
                'channels'   => $channels,
                'locale'     => self::LOCALE,
            ], $pageId);

            Event::dispatch('cms.page.update.after', $page);
        } else {
            $previousHtml = null;

            Event::dispatch('cms.page.create.before');

            $page = $this->pageRepository->create($attributes + ['channels' => [$channelId]]);

            Event::dispatch('cms.page.create.after', $page);
        }

        PolicyGenerationProxy::modelClass()::query()->updateOrCreate(
            ['cms_page_id' => $page->id, 'locale' => self::LOCALE],
            [
                'template_key'        => $key,
                'generated_html_hash' => $this->hasher->htmlHash($html),
                'settings_hash'       => $this->hasher->hash($settings, $template['depends']),
                'previous_html'       => $previousHtml,
                'generated_at'        => now(),
                'generated_by'        => $adminId,
            ]
        );
    }
}
