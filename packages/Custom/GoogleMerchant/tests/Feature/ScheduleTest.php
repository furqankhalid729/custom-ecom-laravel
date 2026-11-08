<?php

require_once __DIR__.'/../Helpers.php';

use Custom\GoogleMerchant\Console\GmcSchedule;
use Illuminate\Console\Scheduling\Schedule;

function gmcEvents(): array
{
    $schedule = new Schedule;

    app(GmcSchedule::class)->define($schedule);

    return collect($schedule->events())->mapWithKeys(fn ($e) => [$e->description => $e])->all();
}

it('drains the gmc queue every minute in scheduler mode', function () {
    config(['google_merchant.queue.runner' => 'scheduler']);

    $events = gmcEvents();

    expect($events)->toHaveKeys(['gmc:queue-run', 'gmc:scheduler-heartbeat', 'gmc:worker-heartbeat', 'gmc:refresh', 'gmc:retry-failed'])
        ->and($events['gmc:queue-run']->expression)->toBe('* * * * *')
        ->and($events['gmc:queue-run']->command)->toContain('gmc:queue-run')
        ->and($events['gmc:scheduler-heartbeat']->expression)->toBe('* * * * *')
        ->and($events['gmc:worker-heartbeat']->expression)->toBe('*/5 * * * *')
        ->and($events['gmc:refresh']->expression)->toBe('0 1 * * *')
        ->and($events['gmc:retry-failed']->expression)->toBe('0 * * * *');
});

it('leaves the queue to an external worker in daemon mode', function () {
    config(['google_merchant.queue.runner' => 'daemon']);

    expect(gmcEvents())->not->toHaveKey('gmc:queue-run')->toHaveKey('gmc:refresh');
});

it('only runs sync tasks while sync is enabled', function () {
    gmcConfig(['settings.enabled' => 0]);

    expect(gmcEvents()['gmc:refresh']->filtersPass(app()))->toBeFalse();

    gmcConfig(['settings.enabled' => 1]);

    expect(gmcEvents()['gmc:refresh']->filtersPass(app()))->toBeTrue();
});
