<?php

namespace Custom\StorePolicy\Services;

use Webkul\Core\Repositories\ChannelRepository;
use Webkul\Theme\Models\ThemeCustomizationProxy;

/**
 * Keeps the channel's footer_links theme customization linking to policy pages.
 * Only adds missing links; never removes, reorders or duplicates existing ones.
 */
class FooterLinkSync
{
    public const LOCALE = 'en';

    public const COLUMN = 'column_2';

    public function __construct(protected ChannelRepository $channelRepository) {}

    public function ensureLinks(int $channelId, array $templateKeys): void
    {
        $missing = $this->missingLinks($channelId, $templateKeys);

        if (! $missing) {
            return;
        }

        $customization = $this->customization($channelId) ?? $this->createCustomization($channelId);

        $translation = $customization->translateOrNew(self::LOCALE);

        $options = $translation->options ?: [];
        $column = $options[self::COLUMN] ?? [];

        $sortOrder = $column ? max(array_map(fn ($link) => (int) ($link['sort_order'] ?? 0), $column)) : 0;

        foreach ($missing as $key) {
            $template = config("store_policy.templates.{$key}");

            $column[] = [
                'url'        => rtrim(config('app.url'), '/').'/page/'.$template['url_key'],
                'title'      => $template['title'],
                'sort_order' => ++$sortOrder,
            ];
        }

        $options[self::COLUMN] = $column;

        $translation->options = $options;

        $customization->save();
        $translation->save();
    }

    /**
     * Template keys whose page is not linked in the footer.
     */
    public function missingLinks(int $channelId, array $templateKeys): array
    {
        $options = $this->customization($channelId)?->translate(self::LOCALE)?->options ?: [];

        $linkedPaths = [];

        foreach ($options as $links) {
            foreach ((array) $links as $link) {
                $linkedPaths[] = rtrim((string) parse_url($link['url'] ?? '', PHP_URL_PATH), '/');
            }
        }

        return array_values(array_filter($templateKeys, function ($key) use ($linkedPaths) {
            $suffix = '/page/'.config("store_policy.templates.{$key}.url_key");

            foreach ($linkedPaths as $path) {
                if (str_ends_with($path, $suffix)) {
                    return false;
                }
            }

            return true;
        }));
    }

    protected function customization(int $channelId)
    {
        $channel = $this->channelRepository->find($channelId);

        return ThemeCustomizationProxy::modelClass()::query()
            ->where('type', 'footer_links')
            ->where('channel_id', $channelId)
            ->where('theme_code', $channel?->theme)
            ->first();
    }

    protected function createCustomization(int $channelId)
    {
        $channel = $this->channelRepository->findOrFail($channelId);

        $model = ThemeCustomizationProxy::modelClass();

        return $model::query()->create([
            'type'       => 'footer_links',
            'name'       => 'Footer Links',
            'sort_order' => (int) $model::query()->where('channel_id', $channelId)->max('sort_order') + 1,
            'status'     => 1,
            'channel_id' => $channelId,
            'theme_code' => $channel->theme,
        ]);
    }
}
