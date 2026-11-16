<?php

namespace Custom\GoogleMerchant\Http\Controllers\Admin;

use Custom\GoogleCore\Audit\AuditLogger;
use Custom\GoogleMerchant\Api\Exceptions\GmcApiException;
use Custom\GoogleMerchant\Models\GmcProductProxy;
use Custom\GoogleMerchant\Models\GmcTarget;
use Custom\GoogleMerchant\Models\GmcTargetProxy;
use Custom\GoogleMerchant\Services\BulkSyncService;
use Custom\GoogleMerchant\Services\ConnectionService;
use Custom\GoogleMerchant\Services\DataSourceProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;

/**
 * Markets: one target = countries + language + currency = one Merchant API data source.
 *
 * A market's identity (feed label, language, countries) is what Google keys offers on,
 * so it is never edited in place: pause/resume only, and "change" means remove + add.
 */
class TargetController extends Controller
{
    public function __construct(
        protected ConnectionService $connection,
        protected DataSourceProvisioner $provisioner,
        protected BulkSyncService $bulk,
        protected AuditLogger $audit,
    ) {}

    public function index(): View
    {
        $account = $this->connection->current();
        $channel = core()->getDefaultChannel();

        $targets = $account ? $account->targets()->orderBy('id')->get() : collect();

        $counts = GmcProductProxy::modelClass()::query()
            ->whereIn('gmc_target_id', $targets->pluck('id'))
            ->select('gmc_target_id', DB::raw('count(*) as total'))
            ->groupBy('gmc_target_id')
            ->pluck('total', 'gmc_target_id');

        return view('google_merchant::admin.settings.targets', [
            'account'    => $account,
            'targets'    => $targets,
            'counts'     => $counts,
            'locales'    => $channel->locales->pluck('code')->all(),
            'currencies' => $channel->currencies->pluck('code')->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $channel = core()->getDefaultChannel();
        $account = $this->connection->current();

        $request->merge(['countries' => $this->parseCountries($request->input('countries'))]);

        $data = $request->validate([
            'locale'      => ['required', Rule::in($channel->locales->pluck('code')->all())],
            'currency'    => ['required', Rule::in($channel->currencies->pluck('code')->all()), function ($attribute, $value, $fail) {
                if (! $this->hasRate($value)) {
                    $fail(trans('google_merchant::app.targets.no-rate', ['currency' => $value]));
                }
            }],
            'countries'   => ['required', 'array', 'min:1', function ($attribute, $value, $fail) {
                $known = DB::table('countries')->whereIn('code', $value)->pluck('code')->all();
                $bad = array_filter($value, fn ($c) => ! preg_match('/^[A-Z]{2}$/', $c) || ! in_array($c, $known, true));

                if ($bad) {
                    $fail(trans('google_merchant::app.targets.bad-country', ['codes' => implode(', ', $bad)]));
                }
            }],
            'feed_label'  => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9_-]+$/'],
        ], [
            'locale.in'   => trans('google_merchant::app.store.language-not-in-channel'),
            'currency.in' => trans('google_merchant::app.store.currency-not-in-channel'),
        ]);

        if (! $account?->isConnected() || ! $account->merchant_id) {
            return back()->with('warning', trans('google_merchant::app.targets.not-connected'));
        }

        $feedLabel = $data['feed_label'] ?? $data['countries'][0];
        $language = strtolower(strtok($data['locale'], '_-'));

        if ($account->targets()->where('feed_label', $feedLabel)->where('content_language', $language)->exists()) {
            return back()->withInput()->withErrors(['feed_label' => trans('google_merchant::app.targets.duplicate', ['label' => $feedLabel, 'language' => $language])]);
        }

        /** @var GmcTarget $target */
        $target = GmcTargetProxy::modelClass()::create([
            'gmc_account_id'   => $account->id,
            'channel_id'       => $channel->id,
            'locale'           => $data['locale'],
            'content_language' => $language,
            'feed_label'       => $feedLabel,
            'countries'        => $data['countries'],
            'currency'         => $data['currency'],
            'enabled'          => true,
        ]);

        try {
            $this->provisioner->ensure($target);
        } catch (GmcApiException $e) {
            $target->delete();

            return back()->withInput()->with('error', trans('google_merchant::app.store.provision-failed', ['reason' => $e->getMessage()]));
        }

        $runs = $this->bulk->start(['target_id' => $target->id], 'full', auth()->guard('admin')->id());

        $this->audit->record(ConnectionService::AUDIT, 'target.created', [
            'target_id' => $target->id,
            'label'     => $feedLabel,
            'language'  => $language,
            'currency'  => $data['currency'],
            'countries' => $data['countries'],
        ]);

        return redirect()->route('admin.google_merchant.targets.index')
            ->with('success', trans('google_merchant::app.targets.created', ['label' => $feedLabel, 'runs' => count($runs)]));
    }

    /** Pause or resume: a paused market keeps its ledger and its offers, it just stops receiving updates. */
    public function update(Request $request, int $id): RedirectResponse
    {
        $target = $this->find($id);

        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        $target->forceFill(['enabled' => $enabled])->save();

        $this->audit->record(ConnectionService::AUDIT, $enabled ? 'target.resumed' : 'target.paused', ['target_id' => $target->id]);

        return back()->with('success', trans('google_merchant::app.targets.'.($enabled ? 'resumed' : 'paused-ok'), ['label' => $target->feed_label]));
    }

    /** Remove: stop syncing and delete the market's offers from Google. The ledger stays as history. */
    public function destroy(int $id): RedirectResponse
    {
        $target = $this->find($id);

        $target->forceFill(['enabled' => false])->save();

        $this->bulk->startDeleteAllForTarget($target, auth()->guard('admin')->id());

        $this->audit->record(ConnectionService::AUDIT, 'target.removed', ['target_id' => $target->id]);

        return back()->with('success', trans('google_merchant::app.targets.removed', ['label' => $target->feed_label]));
    }

    protected function find(int $id): GmcTarget
    {
        $account = $this->connection->current();

        abort_unless($account, 404);

        return $account->targets()->findOrFail($id);
    }

    /** "de, at" / "DE AT" / ["de"] → ["DE", "AT"] */
    protected function parseCountries(mixed $input): array
    {
        $parts = is_array($input) ? $input : preg_split('/[\s,;]+/', (string) $input, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_map(fn ($c) => strtoupper(trim((string) $c)), $parts)));
    }

    protected function hasRate(string $currency): bool
    {
        if ($currency === core()->getBaseCurrencyCode()) {
            return true;
        }

        $id = DB::table('currencies')->where('code', $currency)->value('id');

        return $id && DB::table('currency_exchange_rates')->where('target_currency', $id)->where('rate', '>', 0)->exists();
    }
}
