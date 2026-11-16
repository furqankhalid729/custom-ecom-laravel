<?php

require_once __DIR__.'/../Helpers.php';

use Custom\GoogleMerchant\Console\GmcSchedule;
use Custom\GoogleMerchant\Jobs\PruneLogsJob;
use Custom\GoogleMerchant\Jobs\SendDigestJob;
use Custom\GoogleMerchant\Mail\ReauthRequired;
use Custom\GoogleMerchant\Mail\SyncFailureDigest;
use Custom\GoogleMerchant\Models\GmcProduct;
use Custom\GoogleMerchant\Models\GmcSyncLog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    gmcConfig(['settings.enabled' => 1, 'settings.notify_email' => 'alerts@shop.example.com']);

    Mail::fake();
    Cache::flush();

    $this->account = gmcAccount();
    $this->target = gmcTarget($this->account);

    $this->row = fn (string $offerId, array $attrs) => GmcProduct::create(array_merge(
        ['gmc_target_id' => $this->target->id, 'offer_id' => $offerId, 'state' => 'synced'],
        $attrs
    ));
});

it('emails once when the account needs reconnecting, even if it happens twice', function () {
    $this->account->forceFill(['status' => 'needs_reauth', 'last_error' => 'Token revoked'])->save();

    $this->account->forceFill(['status' => 'connected'])->save();
    $this->account->forceFill(['status' => 'needs_reauth'])->save();

    Mail::assertSent(ReauthRequired::class, 1);
    Mail::assertSent(ReauthRequired::class, fn ($mail) => $mail->hasTo('alerts@shop.example.com'));
});

it('does not send a digest when nothing new went wrong', function () {
    // Old problems only.
    ($this->row)('OLD-FAIL', ['state' => 'failed', 'last_error' => 'boom', 'last_attempt_at' => now()->subDays(3)]);
    ($this->row)('OLD-DIS', ['google_status' => 'disapproved', 'google_status_changed_at' => now()->subDays(3)]);

    (new SendDigestJob)->handle();

    Mail::assertNothingSent();
});

it('sends a digest with the new problems of the last 24 hours', function () {
    $issue = ['code' => 'image_too_small', 'severity' => 'DISAPPROVED', 'attribute' => 'image_link', 'description' => 'Image too small',
        'detail' => null, 'documentation' => null, 'resolution' => 'merchant_action'];

    ($this->row)('FAIL-1', ['state' => 'failed', 'last_error' => '[productAttributes.gtins] Invalid GTIN value.', 'last_attempt_at' => now()->subHours(2)]);
    ($this->row)('INV-1', ['state' => 'invalid', 'validation_errors' => ['brand' => 'The product has no brand.'], 'last_attempt_at' => now()->subHours(3)]);
    ($this->row)('INV-2', ['state' => 'invalid', 'validation_errors' => ['brand' => 'The product has no brand.'], 'last_attempt_at' => now()->subHours(4)]);
    ($this->row)('DIS-1', ['google_status' => 'disapproved', 'google_issues' => [$issue], 'google_status_changed_at' => now()->subHour()]);
    ($this->row)('OLD-FAIL', ['state' => 'failed', 'last_error' => 'boom', 'last_attempt_at' => now()->subDays(3)]);

    (new SendDigestJob)->handle();

    Mail::assertSent(SyncFailureDigest::class, function (SyncFailureDigest $mail) {
        expect($mail->counts)->toBe(['failed' => 1, 'invalid' => 2, 'disapproved' => 1])
            ->and(collect($mail->problems)->pluck('description')->all())->toContain('Image too small', 'The product has no brand.')
            ->and(count($mail->problems))->toBeLessThanOrEqual(10)
            ->and($mail->hasTo('alerts@shop.example.com'))->toBeTrue();

        $html = $mail->render();

        expect($html)->toContain('Image too small')
            ->toContain(route('admin.google_merchant.diagnostics.index'));

        return true;
    });
});

it('falls back to the super admin when no notification email is set', function () {
    gmcConfig(['settings.notify_email' => '']);

    ($this->row)('FAIL-1', ['state' => 'failed', 'last_error' => 'boom', 'last_attempt_at' => now()->subHour()]);

    (new SendDigestJob)->handle();

    $superAdmin = DB::table('admins')
        ->join('roles', 'roles.id', '=', 'admins.role_id')
        ->where('roles.permission_type', 'all')
        ->orderBy('admins.id')
        ->value('admins.email');

    Mail::assertSent(SyncFailureDigest::class, fn ($mail) => $mail->hasTo($superAdmin));
});

it('stays quiet when failure notifications are turned off', function () {
    gmcConfig(['settings.notify_on_failure' => 0]);

    ($this->row)('FAIL-1', ['state' => 'failed', 'last_error' => 'boom', 'last_attempt_at' => now()->subHour()]);
    (new SendDigestJob)->handle();

    $this->account->forceFill(['status' => 'needs_reauth'])->save();

    Mail::assertNothingSent();
});

it('prunes sync logs older than the retention period', function () {
    gmcConfig(['settings.log_retention_days' => 30]);

    $log = fn (int $daysAgo) => tap(GmcSyncLog::create([
        'gmc_target_id' => $this->target->id, 'offer_id' => "LOG-$daysAgo", 'operation' => 'upsert', 'status' => 'success',
    ]), fn ($l) => DB::table('gmc_sync_logs')->where('id', $l->id)->update(['created_at' => now()->subDays($daysAgo)]));

    $old = $log(40);
    $recent = $log(10);

    (new PruneLogsJob)->handle(app(\Custom\GoogleMerchant\Support\Settings::class));

    expect(GmcSyncLog::find($old->id))->toBeNull()
        ->and(GmcSyncLog::find($recent->id))->not->toBeNull();
});

it('schedules the digest at 08:00 and pruning at 03:00', function () {
    $schedule = new Schedule;
    app(GmcSchedule::class)->define($schedule);

    $events = collect($schedule->events())->keyBy('description');

    expect($events['gmc:digest']->expression)->toBe('0 8 * * *')
        ->and($events['gmc:prune-logs']->expression)->toBe('0 3 * * *');
});
