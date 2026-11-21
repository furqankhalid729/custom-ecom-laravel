<?php

namespace Custom\GoogleMerchant\Jobs;

use Custom\GoogleMerchant\Api\Exceptions\AuthException;
use Custom\GoogleMerchant\Api\Exceptions\TransientApiException;
use Custom\GoogleMerchant\Enums\AccountStatus;
use Custom\GoogleMerchant\Jobs\Concerns\RunsOnGmcQueue;
use Custom\GoogleMerchant\Jobs\Middleware\EnsureAccountReady;
use Custom\GoogleMerchant\Models\GmcAccountProxy;
use Custom\GoogleMerchant\Services\AccountPolicySync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Pushes shipping settings and return policies for one account. Always queued:
 * Google calls never run inside the admin's save request. One pending push per account.
 */
class PushAccountPoliciesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOnGmcQueue;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $uniqueFor = 600;

    public function __construct(public int $accountId)
    {
        $this->useGmcQueue();
    }

    public function uniqueId(): string
    {
        return (string) $this->accountId;
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function handle(AccountPolicySync $sync): void
    {
        $account = GmcAccountProxy::modelClass()::find($this->accountId);

        if ($account?->status !== AccountStatus::Connected->value || ! $account->merchant_id) {
            return;
        }

        try {
            $sync->push($account);
        } catch (TransientApiException $e) {
            $this->release($e->retryAfter + random_int(0, 15));
        } catch (AuthException) {
            $this->release(EnsureAccountReady::REAUTH_RELEASE_SECONDS);
        }
    }
}
