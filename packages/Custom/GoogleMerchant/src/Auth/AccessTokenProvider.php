<?php

namespace Custom\GoogleMerchant\Auth;

use Custom\GoogleMerchant\Models\GmcAccount;

interface AccessTokenProvider
{
    /** A valid bearer token, refreshed when close to expiry. */
    public function accessToken(GmcAccount $account): string;

    /** Refresh now, regardless of the stored expiry (used after a 401). */
    public function forceRefresh(GmcAccount $account): string;
}
