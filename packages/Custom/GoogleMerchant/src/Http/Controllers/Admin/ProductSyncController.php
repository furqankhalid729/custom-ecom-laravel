<?php

namespace Custom\GoogleMerchant\Http\Controllers\Admin;

use Custom\GoogleCore\Audit\AuditLogger;
use Custom\GoogleMerchant\DataGrids\GmcProductDataGrid;
use Custom\GoogleMerchant\Models\GmcProduct;
use Custom\GoogleMerchant\Models\GmcProductProxy;
use Custom\GoogleMerchant\Services\ConnectionService;
use Custom\GoogleMerchant\Sync\SyncDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Product\Models\Product;
use Webkul\Product\Repositories\ProductRepository;

class ProductSyncController extends Controller
{
    public function __construct(
        protected SyncDispatcher $dispatcher,
        protected ProductRepository $productRepository,
        protected AuditLogger $audit,
    ) {}

    public function index()
    {
        if (request()->ajax()) {
            return datagrid(GmcProductDataGrid::class)->process();
        }

        return view('google_merchant::admin.products.index');
    }

    public function sync(GmcProduct $gmcProduct): JsonResponse
    {
        return $this->queue([$gmcProduct->id], force: true, message: 'sync-queued');
    }

    public function retry(GmcProduct $gmcProduct): JsonResponse
    {
        return $this->queue([$gmcProduct->id], force: false, message: 'retry-queued', resetRetries: true);
    }

    public function massSync(Request $request): JsonResponse
    {
        return $this->queue($this->indices($request), force: true, message: 'sync-queued');
    }

    public function massRetry(Request $request): JsonResponse
    {
        return $this->queue($this->indices($request), force: false, message: 'retry-queued', resetRetries: true);
    }

    public function exclude(GmcProduct $gmcProduct): JsonResponse
    {
        return $this->setSyncFlag([$gmcProduct->id], false, 'exclude');
    }

    public function include(GmcProduct $gmcProduct): JsonResponse
    {
        return $this->setSyncFlag([$gmcProduct->id], true, 'include');
    }

    public function massExclude(Request $request): JsonResponse
    {
        return $this->setSyncFlag($this->indices($request), false, 'exclude');
    }

    /** "Remove from Google" = switch the product's sync flag off; the normal sync then deletes the offer. */
    public function remove(GmcProduct $gmcProduct): JsonResponse
    {
        return $this->setSyncFlag([$gmcProduct->id], false, 'remove');
    }

    public function syncStoreProduct(Product $product): RedirectResponse
    {
        $count = $this->dispatcher->queue([$product->id], 'manual', 0, true);

        $this->audit->record(ConnectionService::AUDIT, 'sync_product', ['product_id' => $product->id]);

        session()->flash($count ? 'success' : 'warning', trans('google_merchant::app.products.'.($count ? 'sync-queued' : 'nothing-queued')));

        return redirect()->back();
    }

    protected function queue(array $ids, bool $force, string $message, bool $resetRetries = false): JsonResponse
    {
        $rows = $this->rows($ids);

        if ($resetRetries) {
            GmcProductProxy::modelClass()::query()->whereIn('id', $rows->pluck('id'))->update(['retry_cycles' => 0]);
        }

        $count = 0;

        foreach ($rows->unique(fn ($r) => $r->gmc_target_id.':'.$r->root_product_id) as $row) {
            $this->dispatcher->dispatchJob($row->root_product_id, $row->gmc_target_id, 0, $force);
            $count++;
        }

        $this->audit->record(ConnectionService::AUDIT, $force ? 'sync' : 'retry', ['offers' => $rows->pluck('offer_id')->take(50)->all()]);

        return new JsonResponse(['message' => trans("google_merchant::app.products.$message", ['count' => $count])]);
    }

    /**
     * Writes the gmc_sync attribute through the product repository and fires
     * catalog.product.update.* exactly like Bagisto's own mass-status action.
     */
    protected function setSyncFlag(array $ids, bool $value, string $action): JsonResponse
    {
        $productIds = $this->rows($ids)->pluck('product_id')->filter()->unique();

        foreach ($productIds as $productId) {
            Event::dispatch('catalog.product.update.before', $productId);

            $product = $this->productRepository->update(['gmc_sync' => $value ? 1 : 0], $productId, ['gmc_sync']);

            Event::dispatch('catalog.product.update.after', $product);
        }

        $this->audit->record(ConnectionService::AUDIT, $action, ['product_ids' => $productIds->values()->all()]);

        return new JsonResponse(['message' => trans("google_merchant::app.products.$action-done", ['count' => $productIds->count()])]);
    }

    protected function rows(array $ids)
    {
        return GmcProductProxy::modelClass()::query()
            ->whereIn('id', array_map('intval', $ids))
            ->whereNotNull('root_product_id')
            ->get(['id', 'product_id', 'root_product_id', 'gmc_target_id', 'offer_id']);
    }

    protected function indices(Request $request): array
    {
        return $request->validate(['indices' => ['required', 'array'], 'indices.*' => ['integer']])['indices'];
    }
}
