<?php

namespace Custom\GoogleMerchant\Api;

use Custom\GoogleMerchant\Api\Exceptions\TransientApiException;
use Custom\GoogleMerchant\Models\GmcAccount;
use Custom\GoogleMerchant\Support\Settings;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Per-account request budget shared by every worker (cache-backed).
 */
class ApiThrottle
{
    public function __construct(protected Settings $settings) {}

    public function hit(GmcAccount $account): void
    {
        $key = 'gmc-api:'.$account->id;

        $max = max(1, $this->settings->int('settings.rate_limit_per_minute', 60));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new TransientApiException(
                'Local rate limit reached for this Merchant Center account.',
                429,
                null,
                max(1, RateLimiter::availableIn($key))
            );
        }

        RateLimiter::hit($key, 60);
    }
}
