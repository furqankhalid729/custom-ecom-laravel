<?php

use Custom\GoogleCore\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

it('records audit entries without secrets', function () {
    app(AuditLogger::class)->record('merchant_center', 'connect', [
        'refresh_token'        => 'x',
        'client_secret'        => 'y',
        'service_account_json' => '{}',
        'merchant_id'          => '1',
    ]);

    $row = DB::table('google_audit_logs')->latest('id')->first();

    $context = json_decode($row->context, true);

    expect($row->integration)->toBe('merchant_center')
        ->and($row->action)->toBe('connect')
        ->and($context)->toBe(['merchant_id' => '1']);
});
