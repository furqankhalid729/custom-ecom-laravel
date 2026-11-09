<?php

namespace Custom\GoogleMerchant\Http\Controllers\Admin;

use Custom\GoogleCore\Audit\AuditLogger;
use Custom\GoogleMerchant\Api\Exceptions\GmcApiException;
use Custom\GoogleMerchant\Services\ConnectionService;
use Custom\GoogleMerchant\Services\StoreSettingsService;
use Custom\GoogleMerchant\Services\UrlProbe;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;

class StoreSettingsController extends Controller
{
    public const URL_FIELDS = ['website_url', 'return_policy_url', 'refund_policy_url', 'terms_url', 'privacy_url'];

    public function __construct(
        protected StoreSettingsService $store,
        protected ConnectionService $connection,
        protected UrlProbe $probe,
        protected AuditLogger $audit,
    ) {}

    public function edit(): View
    {
        $channel = core()->getDefaultChannel();

        return view('google_merchant::admin.settings.store', [
            'values'     => $this->store->current(),
            'locales'    => $channel->locales->pluck('code')->all(),
            'currencies' => $channel->currencies->pluck('code')->all(),
            'report'     => session('gmc_url_report', []),
            'target'     => $this->connection->current()?->targets()->where('enabled', true)->orderBy('id')->first(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $channel = core()->getDefaultChannel();

        $https = function (string $attribute, mixed $value, \Closure $fail) {
            if ($value && parse_url((string) $value, PHP_URL_SCHEME) !== 'https') {
                $fail(trans('google_merchant::app.store.must-be-https'));
            }
        };

        $data = $request->validate([
            'website_url'       => ['nullable', 'url', $https],
            'country'           => ['required', 'string', 'size:2', Rule::exists('countries', 'code')],
            'language'          => ['required', Rule::in($channel->locales->pluck('code')->all())],
            'currency'          => ['required', Rule::in($channel->currencies->pluck('code')->all())],
            'return_policy_url' => ['nullable', 'url', $https],
            'refund_policy_url' => ['nullable', 'url', $https],
            'terms_url'         => ['nullable', 'url', $https],
            'privacy_url'       => ['nullable', 'url', $https],
            'shipping_note'     => ['nullable', 'string', 'max:2000'],
            'tax_note'          => ['nullable', 'string', 'max:2000'],
            'confirm_resync'    => ['nullable', 'boolean'],
        ], [
            'language.in' => trans('google_merchant::app.store.language-not-in-channel'),
            'currency.in' => trans('google_merchant::app.store.currency-not-in-channel'),
        ]);

        $account = $this->connection->current();
        $confirmed = (bool) ($data['confirm_resync'] ?? false);

        if ($this->store->changesMarket($account, $data) && $this->store->hasOffersInGoogle($account) && ! $confirmed) {
            throw ValidationException::withMessages(['confirm_resync' => trans('google_merchant::app.store.confirm-required')]);
        }

        try {
            $result = $this->store->save($data, $account, $confirmed, auth()->guard('admin')->id());
        } catch (GmcApiException $e) {
            session()->flash('error', trans('google_merchant::app.store.provision-failed', ['reason' => $e->getMessage()]));

            return redirect()->route('admin.google_merchant.store.edit')->withInput();
        }

        $report = collect(self::URL_FIELDS)
            ->filter(fn ($field) => ! empty($data[$field]))
            ->map(fn ($field) => ['field' => $field] + $this->probe->check($data[$field]))
            ->values()
            ->all();

        $this->audit->record(ConnectionService::AUDIT, 'store_settings', [
            'country'        => $data['country'],
            'language'       => $data['language'],
            'currency'       => $data['currency'],
            'market_changed' => $result['market_changed'],
        ]);

        session()->flash('gmc_url_report', $report);
        session()->flash('success', trans('google_merchant::app.store.'.($result['market_changed'] ? 'saved-market' : 'saved'), ['runs' => $result['runs']]));

        return redirect()->route('admin.google_merchant.store.edit');
    }
}
