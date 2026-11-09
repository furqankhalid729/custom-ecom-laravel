<?php

namespace Custom\GoogleMerchant\Http\Controllers\Admin;

use Custom\GoogleCore\Audit\AuditLogger;
use Custom\GoogleMerchant\Http\Requests\AttributeMappingRequest;
use Custom\GoogleMerchant\Http\Requests\CategoryMappingRequest;
use Custom\GoogleMerchant\Mapping\MappingRegistry;
use Custom\GoogleMerchant\Models\GmcAttributeMappingProxy;
use Custom\GoogleMerchant\Models\GmcCategoryMappingProxy;
use Custom\GoogleMerchant\Services\ConnectionService;
use Custom\GoogleMerchant\Services\RefreshStarter;
use Custom\GoogleMerchant\Support\Taxonomy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Category\Repositories\CategoryRepository;

/**
 * Global (all markets) field and category mappings. Saving re-checks every
 * product via the refresh run, because the data sent to Google changes.
 */
class MappingController extends Controller
{
    public function __construct(
        protected AuditLogger $audit,
        protected Taxonomy $taxonomy,
        protected RefreshStarter $refresh,
    ) {}

    public function attributes(): View
    {
        $saved = GmcAttributeMappingProxy::modelClass()::query()->whereNull('gmc_target_id')->get()->keyBy('google_field');

        $fields = collect(MappingRegistry::DEFAULTS)->map(fn ($default, $field) => [
            'field'   => $field,
            'default' => $default,
            'type'    => $saved[$field]->source_type ?? $default[0],
            'value'   => $saved[$field]->source_value ?? $default[1],
            'custom'  => isset($saved[$field]),
        ]);

        return view('google_merchant::admin.mapping.attributes', [
            'fields'     => $fields,
            'attributes' => DB::table('attributes')->orderBy('admin_name')->get(['code', 'admin_name', 'type']),
        ]);
    }

    public function updateAttributes(AttributeMappingRequest $request): RedirectResponse
    {
        $model = GmcAttributeMappingProxy::modelClass();

        foreach ($request->input('mappings') as $field => $mapping) {
            $type = $mapping['type'];
            $value = $type === 'none' ? null : trim((string) ($mapping['value'] ?? ''));

            [$defaultType, $defaultValue] = MappingRegistry::DEFAULTS[$field];

            if ($type === $defaultType && $value === $defaultValue) {
                $model::query()->whereNull('gmc_target_id')->where('google_field', $field)->delete();

                continue;
            }

            $model::query()->updateOrCreate(
                ['gmc_target_id' => null, 'google_field' => $field],
                ['source_type' => $type, 'source_value' => $value],
            );
        }

        $this->afterChange('mapping_attributes', ['fields' => array_keys($request->input('mappings'))]);

        return redirect()->route('admin.google_merchant.mapping.attributes');
    }

    public function categories(CategoryRepository $categories): View
    {
        return view('google_merchant::admin.mapping.categories', [
            'tree'      => $categories->getCategoryTree(),
            'mappings'  => GmcCategoryMappingProxy::modelClass()::query()->get()->keyBy('category_id'),
            'taxonomy'  => $this->taxonomy,
        ]);
    }

    public function updateCategories(CategoryMappingRequest $request): RedirectResponse
    {
        $model = GmcCategoryMappingProxy::modelClass();

        $existing = DB::table('categories')->pluck('id')->all();

        foreach ($request->input('categories') as $categoryId => $mapping) {
            if (! in_array((int) $categoryId, $existing)) {
                continue;
            }

            $google = trim((string) ($mapping['google_product_category'] ?? '')) ?: null;
            $excluded = (bool) ($mapping['excluded'] ?? false);

            if (! $google && ! $excluded) {
                $model::query()->where('category_id', $categoryId)->delete();

                continue;
            }

            $model::query()->updateOrCreate(['category_id' => $categoryId], ['google_product_category' => $google, 'excluded' => $excluded]);
        }

        $this->afterChange('mapping_categories', ['categories' => array_keys($request->input('categories'))]);

        return redirect()->route('admin.google_merchant.mapping.categories');
    }

    public function taxonomy(Request $request): JsonResponse
    {
        return new JsonResponse($this->taxonomy->search((string) $request->query('q', '')));
    }

    protected function afterChange(string $action, array $context): void
    {
        $this->audit->record(ConnectionService::AUDIT, $action, $context);

        // The data sent to Google changed for (potentially) every product.
        $this->refresh->start();

        session()->flash('success', trans('google_merchant::app.mapping.saved'));
    }
}
