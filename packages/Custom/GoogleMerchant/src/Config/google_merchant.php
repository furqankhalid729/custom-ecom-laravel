<?php

return [
    'client_id'     => env('GMC_CLIENT_ID'),
    'client_secret' => env('GMC_CLIENT_SECRET'),

    /* Empty = route('admin.google_merchant.oauth.callback'). */
    'redirect_uri'  => env('GMC_REDIRECT_URI'),

    'api_base'      => env('GMC_API_BASE', 'https://merchantapi.googleapis.com'),
    'http_timeout'  => (int) env('GMC_HTTP_TIMEOUT', 20),

    'queue' => [
        'connection' => env('GMC_QUEUE_CONNECTION', 'database'),
        'name'       => env('GMC_QUEUE', 'gmc'),
        /* scheduler = `schedule:run` drains the queue every minute (cron only); daemon = you run queue:work yourself. */
        'runner'     => env('GMC_QUEUE_RUNNER', 'scheduler'),
    ],

    /* Submitted products expire in Google if not refreshed; resubmit unchanged products after this many days. */
    'resubmit_days' => 25,

    'scopes' => [
        'https://www.googleapis.com/auth/content',
        'openid',
        'email',
    ],

    /* Google taxonomy ids treated as apparel (Apparel & Accessories). */
    'apparel_category_ids' => ['166', '1604'],
];
