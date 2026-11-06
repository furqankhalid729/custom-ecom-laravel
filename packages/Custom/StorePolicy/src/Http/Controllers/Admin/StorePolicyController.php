<?php

namespace Custom\StorePolicy\Http\Controllers\Admin;

use Custom\StorePolicy\Exceptions\MissingSettingsException;
use Custom\StorePolicy\Http\Requests\PolicySettingsRequest;
use Custom\StorePolicy\Repositories\PolicySettingRepository;
use Custom\StorePolicy\Services\PolicyGenerator;
use Custom\StorePolicy\Services\PolicyStatusService;
use Custom\StorePolicy\Services\ReadinessChecker;
use Custom\StorePolicy\Support\Regions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Spatie\ResponseCache\Facades\ResponseCache;
use Webkul\Admin\Http\Controllers\Controller;

class StorePolicyController extends Controller
{
    public function __construct(
        protected PolicySettingRepository $settingRepository,
        protected PolicyStatusService $statusService,
        protected PolicyGenerator $generator,
        protected ReadinessChecker $readinessChecker,
    ) {}

    public function index(): View
    {
        $channelId = $this->channelId();

        return view('store_policy::admin.index', [
            'settings'   => $this->settingRepository->forChannel($channelId)->toSettingsArray(),
            'saved'      => $this->settingRepository->existsForChannel($channelId),
            'statuses'   => $this->statusService->forChannel($channelId),
            'readiness'  => $this->readinessChecker->check($channelId),
            'regionDefs' => Regions::definitions(),
            'outOfDate'  => session('store_policy_out_of_date', []),
        ]);
    }

    public function update(PolicySettingsRequest $request): RedirectResponse
    {
        $channelId = $this->channelId();

        $this->settingRepository->saveForChannel($channelId, $request->settings());

        $this->clearPageCache();

        session()->flash('success', trans('store_policy::app.index.update-success'));

        if ($outOfDate = $this->statusService->outOfDateKeys($channelId)) {
            session()->flash('store_policy_out_of_date', $outOfDate);
        }

        return redirect()->route('admin.cms.store_policies.index');
    }

    public function generate(): RedirectResponse
    {
        $keys = array_keys(config('store_policy.templates'));

        $data = request()->validate([
            'templates'           => ['required', 'array', 'min:1'],
            'templates.*'         => [Rule::in($keys)],
            'confirm_overwrite'   => ['nullable', 'array'],
            'confirm_overwrite.*' => [Rule::in($keys)],
        ]);

        try {
            $result = $this->generator->generate(
                $this->channelId(),
                $data['templates'],
                $data['confirm_overwrite'] ?? [],
                auth()->guard('admin')->id()
            );
        } catch (MissingSettingsException $e) {
            session()->flash('error', trans('store_policy::app.index.missing-settings', ['fields' => implode(', ', $e->missing)]));

            return redirect()->route('admin.cms.store_policies.index');
        }

        $this->clearPageCache();

        if ($result['generated']) {
            session()->flash('success', trans('store_policy::app.index.generate-success', ['pages' => $this->titles($result['generated'])]));
        }

        if ($result['skipped']) {
            session()->flash('warning', trans('store_policy::app.index.generate-skipped', ['pages' => $this->titles($result['skipped'])]));
        }

        return redirect()->route('admin.cms.store_policies.index');
    }

    /**
     * The route parameter is named {policy}: Bagisto binds {template} to a model.
     */
    public function restore(string $policy): RedirectResponse
    {
        $template = $policy;

        abort_unless(config("store_policy.templates.{$template}"), 404);

        try {
            $this->generator->restore($this->channelId(), $template);
        } catch (RuntimeException $e) {
            session()->flash('error', trans('store_policy::app.index.restore-failed'));

            return redirect()->route('admin.cms.store_policies.index');
        }

        $this->clearPageCache();

        session()->flash('success', trans('store_policy::app.index.restore-success', ['page' => $this->titles([$template])]));

        return redirect()->route('admin.cms.store_policies.index');
    }

    /**
     * Policies are store-wide (CMS url keys are unique), so they follow the default channel.
     */
    protected function channelId(): int
    {
        return core()->getDefaultChannel()->id;
    }

    protected function titles(array $keys): string
    {
        return implode(', ', array_map(fn ($key) => config("store_policy.templates.{$key}.title"), $keys));
    }

    /**
     * Business details and shipping/return data appear in every storefront page's
     * structured data, so cached pages must be dropped after a change.
     */
    protected function clearPageCache(): void
    {
        try {
            ResponseCache::clear();
        } catch (\Throwable $e) {
            Log::warning('Store policies: could not clear the page cache: '.$e->getMessage());
        }
    }
}
