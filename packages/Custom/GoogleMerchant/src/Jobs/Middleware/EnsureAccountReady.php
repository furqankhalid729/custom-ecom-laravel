<?php

namespace Custom\GoogleMerchant\Jobs\Middleware;

use Custom\GoogleMerchant\Enums\AccountStatus;
use Custom\GoogleMerchant\Models\GmcTargetProxy;

/**
 * connected → run; needs_reauth → wait 15 min (the admin is told to reconnect);
 * disconnected / missing → drop the job (ledger rows stay pending; reconnecting re-queues them).
 */
class EnsureAccountReady
{
    public const REAUTH_RELEASE_SECONDS = 900;

    public function handle(object $job, \Closure $next): void
    {
        $account = GmcTargetProxy::modelClass()::with('account')->find($job->targetId ?? null)?->account;

        if ($account?->status === AccountStatus::Connected->value) {
            $next($job);

            return;
        }

        if ($account?->status === AccountStatus::NeedsReauth->value) {
            $job->release(self::REAUTH_RELEASE_SECONDS);

            return;
        }

        $job->delete();
    }
}
