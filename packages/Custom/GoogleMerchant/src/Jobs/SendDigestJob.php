<?php

namespace Custom\GoogleMerchant\Jobs;

use Custom\GoogleMerchant\Diagnostics\ProductIssues;
use Custom\GoogleMerchant\Enums\GoogleStatus;
use Custom\GoogleMerchant\Enums\SyncState;
use Custom\GoogleMerchant\Jobs\Concerns\RunsOnGmcQueue;
use Custom\GoogleMerchant\Mail\SyncFailureDigest;
use Custom\GoogleMerchant\Models\GmcProductProxy;
use Custom\GoogleMerchant\Models\GmcTargetProxy;
use Custom\GoogleMerchant\Support\ErrorExplainer;
use Custom\GoogleMerchant\Support\Notifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Mail;

/**
 * Daily email: how many products newly failed, became invalid or were disapproved
 * by Google in the last 24 h, plus the 10 most common problems with fix hints.
 * Nothing new → no email.
 */
class SendDigestJob implements ShouldQueue
{
    use Dispatchable, Queueable, RunsOnGmcQueue;

    public const TOP = 10;

    public function __construct()
    {
        $this->useGmcQueue();
    }

    public function handle(): void
    {
        $notifier = app(Notifier::class);

        if (! $notifier->enabled() || ! ($to = $notifier->recipient())) {
            return;
        }

        $since = now()->subDay();
        $rows = fn () => GmcProductProxy::modelClass()::query();

        $counts = [
            'failed' => $rows()->where('state', SyncState::Failed->value)->where('last_attempt_at', '>=', $since)->count(),
            'invalid' => $rows()->where('state', SyncState::Invalid->value)->where('last_attempt_at', '>=', $since)->count(),
            'disapproved' => $rows()->where('google_status', GoogleStatus::Disapproved->value)->where('google_status_changed_at', '>=', $since)->count(),
        ];

        if (! array_sum($counts)) {
            return;
        }

        Mail::to($to)->send(new SyncFailureDigest($counts, $this->topProblems()));
    }

    /** @return array<int, array{description: string, count: int, hint: string}> */
    protected function topProblems(): array
    {
        $issues = app(ProductIssues::class);
        $problems = collect();

        foreach (GmcTargetProxy::modelClass()::all() as $target) {
            $problems = $problems->merge(collect($issues->groups($target))->flatten(1));
        }

        // Sync failures (Google refused the request), grouped by their explanation.
        $explainer = app(ErrorExplainer::class);

        GmcProductProxy::modelClass()::query()
            ->where('state', SyncState::Failed->value)
            ->select(['id', 'state', 'last_error'])
            ->cursor()
            ->map(fn ($row) => $explainer->explain($row))
            ->groupBy('title')
            ->each(fn ($group, $title) => $problems->push([
                'description' => (string) $title,
                'count' => $group->count(),
                'hint' => (string) $group->first()['hint'],
            ]));

        return $problems
            ->groupBy('description')
            ->map(fn ($same) => [
                'description' => (string) $same->first()['description'],
                'count' => (int) $same->sum('count'),
                'hint' => (string) $same->first()['hint'],
            ])
            ->sortByDesc('count')
            ->take(self::TOP)
            ->values()
            ->all();
    }
}
