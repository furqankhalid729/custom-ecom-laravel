<?php

namespace Custom\GoogleMerchant\Mail;

use Custom\GoogleMerchant\Models\GmcAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Queued on the gmc queue: the account switches to needs_reauth in the middle of an
 * admin request or a sync job, and rendering a mail view there would switch Bagisto's
 * theme view finder away from the admin theme (and a slow mail server would stall the sync).
 */
class ReauthRequired extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public GmcAccount $account)
    {
        $this->onConnection(config('google_merchant.queue.connection', 'database'));
        $this->onQueue(config('google_merchant.queue.name', 'gmc'));
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: trans('google_merchant::app.mail.reauth.subject'));
    }

    public function content(): Content
    {
        return new Content(view: 'google_merchant::emails.reauth', with: [
            'account' => $this->account,
            'url'     => route('admin.google_merchant.connection.index'),
        ]);
    }
}
