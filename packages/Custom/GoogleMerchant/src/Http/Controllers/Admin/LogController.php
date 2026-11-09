<?php

namespace Custom\GoogleMerchant\Http\Controllers\Admin;

use Custom\GoogleMerchant\DataGrids\GmcSyncLogDataGrid;
use Custom\GoogleMerchant\Models\GmcSyncLog;
use Custom\GoogleMerchant\Support\ErrorExplainer;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;

class LogController extends Controller
{
    public function __construct(protected ErrorExplainer $explainer) {}

    public function index()
    {
        if (request()->ajax()) {
            return datagrid(GmcSyncLogDataGrid::class)->process();
        }

        return view('google_merchant::admin.logs.index');
    }

    /** Detail page; JSON when asked for it. The excerpt was redacted when stored. */
    public function show(GmcSyncLog $log): View|JsonResponse
    {
        $data = [
            'log'         => $log,
            'explanation' => $this->explainer->explain($log),
            'pretty'      => $this->pretty($log->response_excerpt),
        ];

        if (request()->expectsJson()) {
            return new JsonResponse($log->only([
                'id', 'created_at', 'product_id', 'offer_id', 'operation', 'status', 'http_status', 'error_message', 'attempt', 'duration_ms', 'response_excerpt',
            ]) + ['explanation' => $data['explanation']]);
        }

        return view('google_merchant::admin.logs.show', $data);
    }

    protected function pretty(?string $excerpt): ?string
    {
        if (! $excerpt) {
            return null;
        }

        $decoded = json_decode($excerpt, true);

        return is_array($decoded) ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $excerpt;
    }
}
