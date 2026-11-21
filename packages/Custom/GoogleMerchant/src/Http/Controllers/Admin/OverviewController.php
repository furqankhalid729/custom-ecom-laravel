<?php

namespace Custom\GoogleMerchant\Http\Controllers\Admin;

use Custom\GoogleMerchant\Services\ConnectionService;
use Custom\GoogleMerchant\Services\DashboardStats;
use Custom\GoogleMerchant\Services\SetupChecklist;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;

class OverviewController extends Controller
{
    public function __construct(
        protected ConnectionService $connection,
        protected DashboardStats $stats,
        protected SetupChecklist $checklist,
    ) {}

    public function index(): View
    {
        $account = $this->connection->current();
        $targets = $account ? $account->targets()->orderBy('id')->get() : collect();
        $target = $targets->firstWhere('id', (int) request('target')) ?? $targets->first();

        return view('google_merchant::admin.overview', [
            'account' => $account,
            'target' => $target,
            'targets' => $targets,
            'stats' => $target ? $this->stats->forTarget($target) : null,
            'checks' => $this->checklist->run($account),
        ]);
    }
}
