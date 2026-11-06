<?php

use Custom\StorePolicy\Repositories\PolicySettingRepository;
use Custom\StorePolicy\Support\Defaults;

it('prefills defaults from the default channel', function () {
    $channel = core()->getDefaultChannel();

    $settings = app(PolicySettingRepository::class)->forChannel($channel->id);

    expect($settings->exists)->toBeFalse();
    expect($settings->business['legal_name'])->toBe($channel->name);
    expect($settings->regions['EU']['countries'])->toHaveCount(27);
    expect($settings->regions['EU']['returns']['window_days'])->toBe(14);
    expect($settings->regions['US']['currency'])->toBe('USD');
    expect($settings->regions['EU']['shipping']['methods'])->toBe([]);
});

it('persists settings per channel', function () {
    $channel = core()->getDefaultChannel();

    $data = Defaults::forChannel($channel);
    $data['business']['email'] = 'support@example.com';

    app(PolicySettingRepository::class)->saveForChannel($channel->id, $data);

    $settings = app(PolicySettingRepository::class)->forChannel($channel->id);

    expect($settings->exists)->toBeTrue();
    expect($settings->business['email'])->toBe('support@example.com');
});
