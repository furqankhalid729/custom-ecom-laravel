<?php

namespace Custom\GoogleMerchant\Support;

use Custom\GoogleMerchant\Mail\ReauthRequired;
use Custom\GoogleMerchant\Models\GmcAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Who gets Google Merchant emails, and the "account needs reconnecting" alert.
 * A mail failure never interrupts the sync that triggered it.
 */
class Notifier
{
    public function __construct(protected Settings $settings) {}

    public function enabled(): bool
    {
        return $this->settings->bool('settings.notify_on_failure', true);
    }

    /** Configured notification email, else the first super admin. */
    public function recipient(): ?string
    {
        $email = trim((string) $this->settings->get('settings.notify_email', ''));

        if ($email !== '') {
            return $email;
        }

        return DB::table('admins')
            ->join('roles', 'roles.id', '=', 'admins.role_id')
            ->where('roles.permission_type', 'all')
            ->where('admins.status', 1)
            ->orderBy('admins.id')
            ->value('admins.email');
    }

    /** Sent once per account per day, however often the token fails. */
    public function reauthRequired(GmcAccount $account): void
    {
        if (! $this->enabled() || ! ($to = $this->recipient())) {
            return;
        }

        if (! Cache::add("gmc:mail:reauth:{$account->id}", 1, 86400)) {
            return;
        }

        try {
            Mail::to($to)->queue(new ReauthRequired($account));
        } catch (\Throwable $e) {
            Log::warning('Google Merchant: reconnect email could not be sent: '.$e->getMessage());
        }
    }
}
