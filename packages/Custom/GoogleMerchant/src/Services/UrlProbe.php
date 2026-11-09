<?php

namespace Custom\GoogleMerchant\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Checks that a page Google must open actually loads. Used when saving store
 * settings only — never during sync.
 */
class UrlProbe
{
    /**
     * @return array{url: string, ok: bool, status: ?int, message: string}
     */
    public function check(string $url): array
    {
        try {
            $response = Http::timeout(5)->withoutRedirecting()->head($url);

            if ($response->status() === 405) {
                $response = Http::timeout(5)->withoutRedirecting()->get($url);
            }
        } catch (ConnectionException $e) {
            return $this->result($url, false, null, trans('google_merchant::app.store.probe-unreachable'));
        }

        $status = $response->status();

        if ($status >= 200 && $status < 300) {
            return $this->result($url, true, $status, trans('google_merchant::app.store.probe-ok'));
        }

        if (in_array($status, [301, 308], true)) {
            $location = (string) $response->header('Location');
            $sameHost = parse_url($location, PHP_URL_HOST) === null
                || strcasecmp((string) parse_url($location, PHP_URL_HOST), (string) parse_url($url, PHP_URL_HOST)) === 0;

            return $sameHost
                ? $this->result($url, true, $status, trans('google_merchant::app.store.probe-ok'))
                : $this->result($url, false, $status, trans('google_merchant::app.store.probe-other-host', ['location' => $location]));
        }

        return $this->result($url, false, $status, trans('google_merchant::app.store.probe-status', ['status' => $status]));
    }

    protected function result(string $url, bool $ok, ?int $status, string $message): array
    {
        return compact('url', 'ok', 'status', 'message');
    }
}
