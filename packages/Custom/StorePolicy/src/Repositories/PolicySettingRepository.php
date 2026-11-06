<?php

namespace Custom\StorePolicy\Repositories;

use Custom\StorePolicy\Contracts\PolicySetting as PolicySettingContract;
use Custom\StorePolicy\Models\PolicySetting;
use Custom\StorePolicy\Support\Defaults;
use Webkul\Core\Eloquent\Repository;
use Webkul\Core\Repositories\ChannelRepository;

class PolicySettingRepository extends Repository
{
    public function model(): string
    {
        return PolicySettingContract::class;
    }

    /**
     * Saved settings for the channel, or an unsaved model filled with defaults.
     */
    public function forChannel(int $channelId): PolicySetting
    {
        $settings = $this->getModel()->newQuery()->where('channel_id', $channelId)->first();

        if ($settings) {
            return $settings;
        }

        $channel = app(ChannelRepository::class)->findOrFail($channelId);

        return $this->getModel()->newInstance(['channel_id' => $channelId] + Defaults::forChannel($channel));
    }

    /**
     * Whether settings have been saved for the channel.
     */
    public function existsForChannel(int $channelId): bool
    {
        return $this->getModel()->newQuery()->where('channel_id', $channelId)->exists();
    }

    public function saveForChannel(int $channelId, array $data): PolicySetting
    {
        return $this->getModel()->newQuery()->updateOrCreate(
            ['channel_id' => $channelId],
            array_intersect_key($data, array_flip(PolicySetting::GROUPS))
        );
    }
}
