<?php

require_once __DIR__.'/../Helpers.php';

use Custom\GoogleMerchant\Api\MerchantApiClient;
use Custom\GoogleMerchant\Console\GmcSchedule;
use Custom\GoogleMerchant\Diagnostics\IssueHints;
use Custom\GoogleMerchant\Diagnostics\StatusParser;
use Custom\GoogleMerchant\Enums\GoogleStatus;
use Custom\GoogleMerchant\Jobs\RefreshStatusesJob;
use Custom\GoogleMerchant\Models\GmcProduct;
use Custom\GoogleMerchant\Models\GmcRun;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    gmcConfig(['settings.enabled' => 1]);

    $this->target = gmcTarget(gmcAccount());

    foreach (['SKU-APPROVED', 'SKU-DISAPPROVED', 'SKU-PARTIAL', 'SKU-PENDING'] as $offerId) {
        GmcProduct::create(['gmc_target_id' => $this->target->id, 'offer_id' => $offerId, 'state' => 'synced']);
    }

    Http::fake(function (Request $request) {
        if (! str_contains($request->url(), '/products/v1/accounts/123456/products')) {
            return Http::response([], 404);
        }

        return Http::response(fixture(str_contains($request->url(), 'pageToken=page-2') ? 'products-list-page2' : 'products-list-page1'));
    });

    /** Runs the status refresh synchronously page after page; returns the number of jobs executed. */
    $this->runStatuses = function (): int {
        Queue::fake();

        Artisan::call('gmc:statuses');

        $ran = 0;

        while ($job = Queue::pushed(RefreshStatusesJob::class)->first(fn ($j) => ! isset($j->ran))) {
            $job->ran = true;
            $job->handle(app(MerchantApiClient::class), app(StatusParser::class));
            $ran++;
        }

        return $ran;
    };

    $this->row = fn (string $offerId) => GmcProduct::where('gmc_target_id', $this->target->id)->where('offer_id', $offerId)->first();
});

it('pages through products one job per page and updates every row', function () {
    expect(($this->runStatuses)())->toBe(2);

    foreach (['SKU-APPROVED', 'SKU-DISAPPROVED', 'SKU-PARTIAL', 'SKU-PENDING'] as $offerId) {
        expect(($this->row)($offerId)->google_checked_at)->not->toBeNull();
    }

    Http::assertSentCount(2);
});

it('marks an offer disapproved everywhere as disapproved with google\'s issue text', function () {
    ($this->runStatuses)();

    $row = ($this->row)('SKU-DISAPPROVED');

    expect($row->google_status)->toBe('disapproved')
        ->and($row->google_issues)->toHaveCount(1)
        ->and($row->google_issues[0])->toMatchArray([
            'code' => 'image_too_small',
            'severity' => 'DISAPPROVED',
            'attribute' => 'image_link',
            'description' => 'Image too small',
            'detail' => 'Use a larger image (at least 100 x 100 pixels)',
            'documentation' => 'https://support.google.com/merchants/answer/6098289',
            'resolution' => 'merchant_action',
        ]);
});

it('marks an offer disapproved in only some destinations as partial', function () {
    ($this->runStatuses)();

    expect(($this->row)('SKU-PARTIAL')->google_status)->toBe('partial')
        ->and(($this->row)('SKU-APPROVED')->google_status)->toBe('approved')
        ->and(($this->row)('SKU-APPROVED')->google_issues)->toBe([])
        ->and(($this->row)('SKU-PENDING')->google_status)->toBe('pending');
});

it('ignores offers google has that the store does not know, and other languages', function () {
    ($this->runStatuses)();

    expect(GmcProduct::where('offer_id', 'SKU-NOT-IN-STORE')->exists())->toBeFalse()
        // The de/DE copy of SKU-APPROVED (disapproved) must not overwrite the en/US row.
        ->and(($this->row)('SKU-APPROVED')->google_status)->toBe('approved');
});

it('records a completed statuses run', function () {
    ($this->runStatuses)();

    $run = GmcRun::where('type', 'statuses')->latest('id')->first();

    expect($run->status)->toBe('completed')
        ->and($run->gmc_target_id)->toBe($this->target->id)
        ->and($run->processed)->toBe(4)
        ->and($run->finished_at)->not->toBeNull();
});

it('parses status rules', function (array $destinations, GoogleStatus $expected) {
    $parsed = app(StatusParser::class)->parse(['productStatus' => ['destinationStatuses' => $destinations]]);

    expect($parsed['google_status'])->toBe($expected);
})->with([
    'no destinations yet' => [[], GoogleStatus::Pending],
    'all approved' => [[['approvedCountries' => ['US', 'CA']]], GoogleStatus::Approved],
    'one country disapproved' => [[['approvedCountries' => ['US'], 'disapprovedCountries' => ['CA']]], GoogleStatus::Partial],
    'pending and disapproved' => [[['pendingCountries' => ['US'], 'disapprovedCountries' => ['CA']]], GoogleStatus::Partial],
    'pending, nothing approved' => [[['pendingCountries' => ['US']]], GoogleStatus::Pending],
    'pending in one, approved other' => [[['pendingCountries' => ['US']], ['approvedCountries' => ['US']]], GoogleStatus::Approved],
    'disapproved everywhere' => [[['disapprovedCountries' => ['US']], ['disapprovedCountries' => ['US']]], GoogleStatus::Disapproved],
]);

it('suggests a fix that names the store field', function () {
    expect(IssueHints::for('image_too_small', 'image_link'))->toContain('Images')
        ->and(IssueHints::for('missing_gtin', 'gtin'))->toContain('GTIN');
});

it('falls back to google\'s own text for unknown issues', function () {
    expect(IssueHints::for('something_new', null, 'Google says do X', 'merchant_action'))->toBe('Google says do X')
        ->and(IssueHints::for('something_new', null, null, null))->toBe('');
});

it('refreshes statuses every six hours while sync is enabled', function () {
    $schedule = new Schedule;

    app(GmcSchedule::class)->define($schedule);

    $event = collect($schedule->events())->firstWhere('description', 'gmc:statuses');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 */6 * * *');
});

it('does not start a second status refresh while one is running', function () {
    Queue::fake();

    Artisan::call('gmc:statuses');
    Artisan::call('gmc:statuses');

    expect(GmcRun::where('type', 'statuses')->count())->toBe(1);
    Queue::assertPushed(RefreshStatusesJob::class, 1);
});

it('stamps the time a status changes, not every time it is checked', function () {
    ($this->runStatuses)();

    $first = ($this->row)('SKU-DISAPPROVED')->google_status_changed_at;

    expect($first)->not->toBeNull();

    DB::table('gmc_runs')->update(['status' => 'completed']);
    $this->travel(7)->hours();

    ($this->runStatuses)();

    expect(($this->row)('SKU-DISAPPROVED')->google_status_changed_at->equalTo($first))->toBeTrue()
        ->and(($this->row)('SKU-DISAPPROVED')->google_checked_at->gt($first))->toBeTrue();
});
