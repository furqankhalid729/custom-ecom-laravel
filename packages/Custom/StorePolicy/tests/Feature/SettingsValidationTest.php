<?php

require_once __DIR__.'/../Helpers.php';

use Custom\StorePolicy\Http\Requests\PolicySettingsRequest;
use Custom\StorePolicy\Support\Defaults;
use Custom\StorePolicy\Support\RequiredFields;
use Illuminate\Support\Facades\Validator;

function validatePolicySettings(array $data): \Illuminate\Validation\Validator
{
    return Validator::make($data, PolicySettingsRequest::rulesFor($data));
}

it('accepts a complete valid settings payload', function () {
    $validator = validatePolicySettings(validSettings());

    expect($validator->errors()->all())->toBe([]);
});

it('rejects EU return window under 14 days', function () {
    $data = validSettings();
    $data['regions']['EU']['returns']['window_days'] = 13;

    expect(validatePolicySettings($data)->errors()->has('regions.EU.returns.window_days'))->toBeTrue();
});

it('rejects EU returns not accepted', function () {
    $data = validSettings();
    $data['regions']['EU']['returns']['accepted'] = false;

    expect(validatePolicySettings($data)->errors()->has('regions.EU.returns.accepted'))->toBeTrue();
});

it('rejects EU refund days over 14', function () {
    $data = validSettings();
    $data['regions']['EU']['returns']['refund_days'] = 15;

    expect(validatePolicySettings($data)->errors()->has('regions.EU.returns.refund_days'))->toBeTrue();
});

it('allows US window under 14 days', function () {
    $data = validSettings();
    $data['regions']['US']['returns']['window_days'] = 7;

    expect(validatePolicySettings($data)->errors()->all())->toBe([]);
});

it('skips region rules when region disabled', function () {
    $data = validSettings();
    $data['regions']['US']['enabled'] = false;
    $data['regions']['US']['shipping']['methods'] = [];

    expect(validatePolicySettings($data)->errors()->all())->toBe([]);
});

it('lists missing required fields', function () {
    $missing = RequiredFields::missing(Defaults::forChannel(core()->getDefaultChannel()));

    expect($missing)->toContain('business.email', 'business.phone', 'regions.EU.shipping.methods');
    expect(RequiredFields::missing(validSettings()))->toBe([]);
});

it('rejects store credit refunds for the EU', function () {
    $data = validSettings();
    $data['regions']['EU']['returns']['refund_method'] = 'store_credit';

    expect(validatePolicySettings($data)->errors()->has('regions.EU.returns.refund_method'))->toBeTrue();
});

it('rejects in-store-only returns for the EU', function () {
    $data = validSettings();
    $data['regions']['EU']['returns']['method'] = 'store';

    expect(validatePolicySettings($data)->errors()->has('regions.EU.returns.method'))->toBeTrue();
});

it('requires at least one enabled region', function () {
    $data = validSettings();
    $data['regions']['EU']['enabled'] = false;
    $data['regions']['US']['enabled'] = false;

    expect(validatePolicySettings($data)->errors()->has('regions'))->toBeTrue();
});
