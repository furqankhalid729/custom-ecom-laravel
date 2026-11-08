<?php

namespace Custom\GoogleMerchant;

use Custom\GoogleCore\Contracts\GoogleIntegration;
use Custom\GoogleMerchant\Enums\AccountStatus;
use Custom\GoogleMerchant\Models\GmcAccountProxy;

class MerchantIntegration implements GoogleIntegration
{
    public function key(): string
    {
        return 'merchant_center';
    }

    public function label(): string
    {
        return trans('google_merchant::app.menu.google-merchant');
    }

    public function isConnected(): bool
    {
        return GmcAccountProxy::modelClass()::query()
            ->where('status', AccountStatus::Connected->value)
            ->exists();
    }

    public function adminRoute(): ?string
    {
        return 'admin.google_merchant.overview';
    }
}
