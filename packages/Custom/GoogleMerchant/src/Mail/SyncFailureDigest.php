<?php

namespace Custom\GoogleMerchant\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SyncFailureDigest extends Mailable
{
    use Queueable;

    /**
     * @param  array{failed: int, invalid: int, disapproved: int}  $counts  new in the last 24 h
     * @param  array<int, array{description: string, count: int, hint: string}>  $problems  top problems overall
     */
    public function __construct(public array $counts, public array $problems) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: trans('google_merchant::app.mail.digest.subject', ['count' => array_sum($this->counts)]));
    }

    public function content(): Content
    {
        return new Content(view: 'google_merchant::emails.digest', with: [
            'counts'   => $this->counts,
            'problems' => $this->problems,
            'url'      => route('admin.google_merchant.diagnostics.index'),
        ]);
    }
}
