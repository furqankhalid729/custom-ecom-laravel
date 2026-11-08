<?php

namespace Custom\GoogleMerchant\Http\Controllers\Admin;

use Custom\GoogleCore\OAuth\OAuthException;
use Custom\GoogleMerchant\Api\Exceptions\GmcApiException;
use Custom\GoogleMerchant\Api\MerchantApiClient;
use Custom\GoogleMerchant\Http\Requests\ServiceAccountRequest;
use Custom\GoogleMerchant\Services\ConnectionService;
use Custom\GoogleMerchant\Services\SetupChecklist;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;

class ConnectionController extends Controller
{
    public function __construct(
        protected ConnectionService $connection,
        protected SetupChecklist $checklist,
        protected MerchantApiClient $client,
    ) {}

    public function index(): View
    {
        $account = $this->connection->current();

        $merchants = [];
        $merchantError = null;

        if ($account?->isConnected() && ! $account->merchant_id) {
            try {
                $merchants = $this->client->listAccounts($account);
            } catch (GmcApiException $e) {
                $merchantError = $e->getMessage();
            }
        }

        return view('google_merchant::admin.connection.index', [
            'account'        => $account,
            'target'         => $account?->targets()->orderBy('id')->first(),
            'checks'         => $this->checklist->run($account),
            'merchants'      => $merchants,
            'merchantError'  => $merchantError,
            'oauthAvailable' => $this->connection->hasOAuthCredentials(),
            'redirectUri'    => $this->connection->redirectUri(),
        ]);
    }

    public function startOAuth(): RedirectResponse
    {
        if (! $this->connection->hasOAuthCredentials()) {
            return $this->back('error', trans('google_merchant::app.connection.missing-credentials'));
        }

        return redirect()->away($this->connection->startOAuth());
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->query('error')) {
            session()->forget(ConnectionService::STATE_KEY);

            return $this->back('error', trans('google_merchant::app.connection.oauth-denied', ['reason' => (string) $request->query('error')]));
        }

        try {
            $this->connection->completeOAuth($request->query('state'), $request->query('code'));
        } catch (OAuthException $e) {
            $key = in_array($e->getMessage(), ['state_mismatch', 'missing_code', 'no_refresh_token'])
                ? 'google_merchant::app.connection.oauth-'.$e->getMessage()
                : null;

            return $this->back('error', $key ? trans($key) : trans('google_merchant::app.connection.oauth-failed', ['reason' => $e->getMessage()]));
        }

        return $this->back('success', trans('google_merchant::app.connection.connected'));
    }

    public function serviceAccount(ServiceAccountRequest $request): RedirectResponse
    {
        try {
            $this->connection->connectServiceAccount($request->keyJson(), $request->input('developer_email'));
        } catch (OAuthException $e) {
            return $this->back('error', trans('google_merchant::app.connection.oauth-failed', ['reason' => $e->getMessage()]));
        }

        return $this->back('success', trans('google_merchant::app.connection.connected'));
    }

    public function selectMerchant(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'merchant_id'     => ['required', 'regex:/^\d{1,20}$/'],
            'developer_email' => ['nullable', 'email'],
        ]);

        $account = $this->connection->current();

        if (! $account?->isConnected()) {
            return $this->back('error', trans('google_merchant::app.connection.not-connected'));
        }

        try {
            $this->connection->selectMerchant($account, $data['merchant_id'], $data['developer_email'] ?? null);
        } catch (GmcApiException|\InvalidArgumentException $e) {
            return $this->back('error', $e->getMessage());
        }

        return $this->back('success', trans('google_merchant::app.connection.merchant-selected'));
    }

    public function disconnect(): RedirectResponse
    {
        if ($account = $this->connection->current()) {
            $this->connection->disconnect($account);
        }

        return $this->back('success', trans('google_merchant::app.connection.disconnected'));
    }

    protected function back(string $type, string $message): RedirectResponse
    {
        session()->flash($type, $message);

        return redirect()->route('admin.google_merchant.connection.index');
    }
}
