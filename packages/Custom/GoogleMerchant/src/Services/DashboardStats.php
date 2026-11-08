<?php

namespace Custom\GoogleMerchant\Services;

use Carbon\Carbon;
use Custom\GoogleMerchant\Models\GmcTarget;
use Custom\GoogleMerchant\Repositories\GmcProductRepository;

class DashboardStats
{
    public function __construct(
        protected GmcProductRepository $ledger,
        protected SetupChecklist $checklist,
    ) {}

    /**
     * @return array{synced: int, pending: int, failed: int, invalid: int, excluded: int, deleting: int,
     *     total: int, approved: int, disapproved: int, google_pending: int, partial: int,
     *     last_success_at: ?Carbon, worker_ok: bool, scheduler_ok: bool}
     */
    public function forTarget(GmcTarget $target): array
    {
        $states = $this->ledger->counts($target->id);

        $google = $this->ledger->query()
            ->where('gmc_target_id', $target->id)
            ->where('state', 'synced')
            ->whereNotNull('google_status')
            ->selectRaw('google_status, count(*) as aggregate')
            ->groupBy('google_status')
            ->pluck('aggregate', 'google_status');

        $last = $this->ledger->query()->where('gmc_target_id', $target->id)->max('last_synced_at');

        $checks = $this->checklist->run($target->account);

        $count = fn (string $state) => (int) ($states[$state] ?? 0);

        return [
            'synced'          => $count('synced'),
            'pending'         => $count('pending'),
            'failed'          => $count('failed'),
            'invalid'         => $count('invalid'),
            'excluded'        => $count('excluded'),
            'deleting'        => $count('deleting'),
            'total'           => array_sum($states) - $count('deleted'),
            'approved'        => (int) ($google['approved'] ?? 0),
            'disapproved'     => (int) ($google['disapproved'] ?? 0),
            'google_pending'  => (int) ($google['pending'] ?? 0),
            'partial'         => (int) ($google['partial'] ?? 0),
            'last_success_at' => $last ? Carbon::parse($last) : null,
            'worker_ok'       => $checks['queue_runner']['ok'],
            'scheduler_ok'    => $checks['scheduler']['ok'],
        ];
    }
}
