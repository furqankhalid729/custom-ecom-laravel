<?php

namespace Custom\GoogleMerchant\Http\Controllers\Admin;

use Custom\GoogleMerchant\Diagnostics\AccountIssues;
use Custom\GoogleMerchant\Diagnostics\ProductIssues;
use Custom\GoogleMerchant\Diagnostics\Quotas;
use Custom\GoogleMerchant\Enums\AccountStatus;
use Custom\GoogleMerchant\Services\ConnectionService;
use Custom\GoogleMerchant\Services\StatusesStarter;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;

class DiagnosticsController extends Controller
{
    public function __construct(
        protected ConnectionService $connection,
        protected ProductIssues $issues,
    ) {}

    public function index(): View
    {
        $account = $this->connection->current();
        $targets = $account ? $account->targets()->orderBy('id')->get() : collect();
        $target = $targets->firstWhere('id', (int) request('target')) ?? $targets->first();
        $live = $account?->status === AccountStatus::Connected->value && $account->merchant_id;

        return view('google_merchant::admin.diagnostics.index', [
            'account' => $account,
            'target' => $target,
            'targets' => $targets,
            'summary' => $target ? $this->issues->summary($target) : null,
            'groups' => $target ? $this->issues->groups($target) : [],
            'accountIssues' => $live ? AccountIssues::get($account) : null,
            'quotas' => $live ? Quotas::get($account) : null,
            'live' => (bool) $live,
        ]);
    }

    public function refresh(StatusesStarter $starter): RedirectResponse
    {
        if ($account = $this->connection->current()) {
            AccountIssues::forget($account);
            Quotas::forget($account);
        }

        $started = $starter->start();

        session()->flash(
            $started ? 'success' : 'warning',
            trans('google_merchant::app.diagnostics.'.($started ? 'refresh-started' : 'refresh-none'))
        );

        return redirect()->route('admin.google_merchant.diagnostics.index');
    }
}
