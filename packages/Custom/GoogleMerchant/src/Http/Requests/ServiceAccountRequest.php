<?php

namespace Custom\GoogleMerchant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ServiceAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'key_file'        => ['required', 'file', 'max:64'],
            'developer_email' => ['nullable', 'email'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('key_file')) {
                    return;
                }

                $key = $this->keyJson();

                if (($key['type'] ?? null) !== 'service_account' || empty($key['client_email']) || empty($key['private_key'])) {
                    $validator->errors()->add('key_file', trans('google_merchant::app.connection.invalid-key'));
                }
            },
        ];
    }

    public function keyJson(): ?array
    {
        $decoded = json_decode((string) $this->file('key_file')?->get(), true);

        return is_array($decoded) ? $decoded : null;
    }
}
