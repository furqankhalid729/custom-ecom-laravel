<?php

namespace Custom\GoogleMerchant\Http\Controllers\Admin;

use Custom\GoogleCore\Audit\AuditLogger;
use Custom\GoogleMerchant\Models\GmcRun;
use Custom\GoogleMerchant\Models\GmcRunProxy;
use Custom\GoogleMerchant\Services\BulkSyncService;
use Custom\GoogleMerchant\Services\ConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Category\Repositories\CategoryRepository;

class RunController extends Controller
{
    public function __construct(
        protected BulkSyncService $bulk,
        protected ConnectionService $connection,
        protected AuditLogger $audit,
    ) {}

    public function index(CategoryRepository $categories): View
    {
        return view('google_merchant::admin.runs.index', [
            'runs'       => GmcRunProxy::modelClass()::query()->latest('id')->limit(20)->get(),
            'categories' => $categories->getCategoryTree(),
            'account'    => $this->connection->current(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mode'           => ['required', Rule::in(BulkSyncService::MODES)],
            'category_ids'   => ['nullable', 'array'],
            'category_ids.*' => ['integer'],
            'product_status' => ['nullable', Rule::in(['any', 'enabled', 'disabled'])],
            'updated_from'   => ['nullable', 'date_format:Y-m-d'],
            'updated_to'     => ['nullable', 'date_format:Y-m-d', 'after_or_equal:updated_from'],
            'product_ids'    => ['nullable', 'string', 'max:20000'],
            'sync_state'     => ['nullable', 'array'],
            'sync_state.*'   => [Rule::in(['pending', 'synced', 'failed', 'invalid', 'excluded'])],
        ]);

        $filters = array_filter([
            'category_ids'   => $data['category_ids'] ?? null,
            'product_status' => $data['product_status'] ?? null,
            'updated_from'   => $data['updated_from'] ?? null,
            'updated_to'     => $data['updated_to'] ?? null,
            'product_ids'    => ($ids = array_values(array_filter(array_map('intval', preg_split('/[\s,]+/', (string) ($data['product_ids'] ?? '')))))) ?: null,
            'sync_state'     => $data['sync_state'] ?? null,
        ]);

        $runs = $this->bulk->start($filters, $data['mode'], auth()->guard('admin')->id());

        $this->audit->record(ConnectionService::AUDIT, 'bulk_sync', ['mode' => $data['mode'], 'filters' => $filters, 'runs' => array_map(fn ($r) => $r->id, $runs)]);

        session()->flash($runs ? 'success' : 'warning', trans('google_merchant::app.runs.'.($runs ? 'run-started' : 'not-started')));

        return redirect()->route('admin.google_merchant.runs.index');
    }

    public function deleteAll(): RedirectResponse
    {
        $account = $this->connection->current();

        $runs = $account?->isConnected() ? $this->bulk->startDeleteAll($account, auth()->guard('admin')->id()) : [];

        $this->audit->record(ConnectionService::AUDIT, 'delete_all', ['runs' => array_map(fn ($r) => $r->id, $runs)]);

        session()->flash($runs ? 'success' : 'warning', trans('google_merchant::app.runs.'.($runs ? 'delete-started' : 'not-started')));

        return redirect()->route('admin.google_merchant.runs.index');
    }

    public function show(GmcRun $run): JsonResponse
    {
        return new JsonResponse([
            'id'          => $run->id,
            'type'        => $run->type,
            'status'      => $run->status,
            'total'       => $run->total,
            'processed'   => $run->processed,
            'succeeded'   => $run->succeeded,
            'failed'      => $run->failed,
            'pending'     => max(0, $run->total - $run->processed),
            'cursor'      => $run->cursor,
            'started_at'  => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ]);
    }

    public function pause(GmcRun $run): JsonResponse|RedirectResponse
    {
        $this->bulk->pause($run);

        return $this->respond('paused');
    }

    public function resume(GmcRun $run): JsonResponse|RedirectResponse
    {
        $this->bulk->resume($run);

        return $this->respond('resumed');
    }

    protected function respond(string $key): JsonResponse|RedirectResponse
    {
        $message = trans("google_merchant::app.runs.$key");

        if (request()->expectsJson()) {
            return new JsonResponse(['message' => $message]);
        }

        session()->flash('success', $message);

        return redirect()->route('admin.google_merchant.runs.index');
    }
}
