<?php

namespace Custom\GoogleMerchant\Mail;

use Custom\GoogleMerchant\Models\GmcAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ReauthRequired extends Mailable
{
    use Queueable;

    public function __construct(public GmcAccount $account) {}

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
