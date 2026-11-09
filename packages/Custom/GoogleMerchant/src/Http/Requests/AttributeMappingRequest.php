<?php

namespace Custom\GoogleMerchant\Http\Requests;

use Custom\GoogleMerchant\Mapping\MappingRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AttributeMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mappings'          => ['required', 'array'],
            'mappings.*.type'   => ['required', Rule::in(MappingRegistry::SOURCE_TYPES)],
            'mappings.*.value'  => ['nullable', 'string', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $unknown = array_diff(array_keys((array) $this->input('mappings', [])), array_keys(MappingRegistry::DEFAULTS));

                if ($unknown) {
                    $validator->errors()->add('mappings', trans('google_merchant::app.mapping.unknown-field', ['fields' => implode(', ', $unknown)]));
                }

                $codes = \Illuminate\Support\Facades\DB::table('attributes')->pluck('code')->all();

                foreach ((array) $this->input('mappings', []) as $field => $mapping) {
                    $type = $mapping['type'] ?? null;
                    $value = trim((string) ($mapping['value'] ?? ''));

                    if ($type === 'attribute' && ! in_array($value, $codes, true)) {
                        $validator->errors()->add("mappings.$field.value", trans('google_merchant::app.mapping.unknown-attribute', ['code' => $value]));
                    }

                    if ($type === 'static' && $value === '') {
                        $validator->errors()->add("mappings.$field.value", trans('google_merchant::app.mapping.static-required'));
                    }
                }
            },
        ];
    }
}
