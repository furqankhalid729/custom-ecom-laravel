<?php

namespace Custom\GoogleMerchant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CategoryMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'categories'                           => ['required', 'array'],
            'categories.*.google_product_category' => ['nullable', 'regex:/^\d{1,10}$/'],
            'categories.*.excluded'                => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'categories.*.google_product_category.regex' => trans('google_merchant::app.mapping.category-id-invalid'),
        ];
    }
}
