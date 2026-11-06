<?php

namespace Custom\StorePolicy\Http\Requests;

use Custom\StorePolicy\Models\PolicySetting;
use Custom\StorePolicy\Support\Defaults;
use Custom\StorePolicy\Support\Regions;
use Custom\StorePolicy\Support\SettingsNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PolicySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return self::rulesFor($this->all());
    }

    /**
     * Cast form strings to the typed settings shape before validating.
     */
    protected function prepareForValidation(): void
    {
        $template = Defaults::forChannel(core()->getDefaultChannel());

        $this->replace(SettingsNormalizer::normalize($this->all(), $template));
    }

    /**
     * The normalized settings (all groups, including disabled regions).
     */
    public function settings(): array
    {
        return $this->only(PolicySetting::GROUPS);
    }

    /**
     * Validation rules for a settings payload. Region rules apply only to enabled regions.
     */
    public static function rulesFor(array $data): array
    {
        $rules = [
            'business.legal_name'          => ['required', 'string', 'max:255'],
            'business.trading_name'        => ['nullable', 'string', 'max:255'],
            'business.logo_url'            => ['nullable', 'string', 'max:2048'],
            'business.address.street'      => ['required', 'string', 'max:255'],
            'business.address.city'        => ['required', 'string', 'max:255'],
            'business.address.region'      => ['nullable', 'string', 'max:255'],
            'business.address.postcode'    => ['required', 'string', 'max:50'],
            'business.address.country'     => ['required', 'string', 'max:100'],
            'business.email'               => ['required', 'email', 'max:255'],
            'business.phone'               => ['required', 'string', 'max:50'],
            'business.hours'               => ['nullable', 'string', 'max:255'],
            'business.registration_number' => ['nullable', 'string', 'max:100'],
            'business.vat_number'          => ['nullable', 'string', 'max:100'],
            'business.website_url'         => ['nullable', 'string', 'max:255'],

            'payment.methods'    => ['nullable', 'array'],
            'payment.methods.*'  => ['string', 'max:255'],
            'payment.charged_at' => ['required', Rule::in(['order', 'shipment'])],

            'privacy.data_collected'    => ['nullable', 'array'],
            'privacy.data_collected.*'  => ['string', 'max:255'],
            'privacy.third_parties'     => ['nullable', 'array'],
            'privacy.third_parties.*'   => ['string', 'max:255'],
            'privacy.uses_cookies'      => ['nullable', 'boolean'],
            'privacy.sells_or_shares'   => ['nullable', 'boolean'],
            'privacy.retention'         => ['nullable', 'string', 'max:1000'],
            'privacy.contact_email'     => ['nullable', 'email', 'max:255'],
            'privacy.eu_representative' => ['nullable', 'string', 'max:1000'],

            'terms.governing_law'  => ['required', 'string', 'max:255'],
            'terms.minimum_age'    => ['nullable', 'integer', 'min:0', 'max:99'],
            'terms.effective_date' => ['nullable', 'date_format:Y-m-d'],

            'regions' => ['required', 'array', function ($attribute, $value, $fail) {
                if (! Regions::enabled(['regions' => (array) $value])) {
                    $fail('Enable at least one selling region.');
                }
            }],
            'regions.*.enabled' => ['nullable', 'boolean'],
        ];

        foreach (array_keys(Regions::definitions()) as $code) {
            if (empty(data_get($data, "regions.{$code}.enabled"))) {
                continue;
            }

            $rules += self::regionRules($code);
        }

        return $rules;
    }

    protected static function regionRules(string $code): array
    {
        $p = "regions.{$code}";

        $rules = [
            "{$p}.countries"                       => ['required', 'array', 'min:1'],
            "{$p}.countries.*"                     => ['string', 'size:2'],
            "{$p}.currency"                        => ['required', 'string', 'size:3'],
            "{$p}.returns.accepted"                => ['nullable', 'boolean'],
            "{$p}.returns.window_days"             => ['required', 'integer', 'min:0', 'max:365'],
            "{$p}.returns.conditions"              => ['nullable', 'array'],
            "{$p}.returns.conditions.*"            => [Rule::in(['unused', 'original_packaging', 'tags_attached'])],
            "{$p}.returns.conditions_note"         => ['nullable', 'string', 'max:1000'],
            "{$p}.returns.non_returnable"          => ['nullable', 'string', 'max:1000'],
            "{$p}.returns.method"                  => ['required', Rule::in(['mail', 'store', 'both'])],
            "{$p}.returns.return_shipping_paid_by" => ['required', Rule::in(['customer', 'store', 'free'])],
            "{$p}.returns.restocking_fee_percent"  => ['nullable', 'integer', 'min:0', 'max:100'],
            "{$p}.returns.refund_method"           => ['required', Rule::in(['original', 'store_credit', 'customer_choice'])],
            "{$p}.returns.refund_days"             => ['required', 'integer', 'min:0', 'max:90'],
            "{$p}.returns.exchanges"               => ['nullable', 'boolean'],
            "{$p}.returns.defect_report_days"      => ['nullable', 'integer', 'min:0', 'max:3650'],
            "{$p}.returns.defect_resolution"       => ['nullable', 'string', 'max:1000'],

            "{$p}.shipping.handling_min_days"     => ['required', 'integer', 'min:0', 'max:60'],
            "{$p}.shipping.handling_max_days"     => ['required', 'integer', 'min:0', 'max:60', "gte:{$p}.shipping.handling_min_days"],
            "{$p}.shipping.cutoff_time"           => ['nullable', 'date_format:H:i'],
            "{$p}.shipping.methods"               => ['required', 'array', 'min:1'],
            "{$p}.shipping.methods.*.name"        => ['required', 'string', 'max:255'],
            "{$p}.shipping.methods.*.carrier"     => ['nullable', 'string', 'max:255'],
            "{$p}.shipping.methods.*.cost"        => ['required', 'numeric', 'min:0'],
            "{$p}.shipping.methods.*.free_over"   => ['nullable', 'numeric', 'min:0'],
            "{$p}.shipping.methods.*.min_days"    => ['required', 'integer', 'min:0', 'max:120'],
            "{$p}.shipping.methods.*.max_days"    => ['required', 'integer', 'min:0', 'max:120', "gte:{$p}.shipping.methods.*.min_days"],
            "{$p}.shipping.tracking"              => ['nullable', 'boolean'],
            "{$p}.shipping.po_boxes"              => ['nullable', 'boolean'],
            "{$p}.shipping.excluded_areas"        => ['nullable', 'string', 'max:1000'],
            "{$p}.shipping.lost_damaged_note"     => ['nullable', 'string', 'max:1000'],
        ];

        if ($code === 'EU') {
            // EU consumer law: the 14-day right of withdrawal always applies,
            // and refunds are due within 14 days of withdrawal.
            $rules["{$p}.countries.*"] = ['string', Rule::in(Regions::EU_COUNTRIES)];
            $rules["{$p}.returns.accepted"] = ['accepted'];
            $rules["{$p}.returns.window_days"] = ['required', 'integer', 'min:14', 'max:365'];
            $rules["{$p}.returns.refund_days"] = ['required', 'integer', 'min:0', 'max:14'];
            // Withdrawal must be possible at a distance, and refunds use the original means of payment.
            $rules["{$p}.returns.method"] = ['required', Rule::in(['mail', 'both'])];
            $rules["{$p}.returns.refund_method"] = ['required', Rule::in(['original', 'customer_choice'])];
            $rules["{$p}.returns.restocking_fee_percent"] = ['nullable', 'integer', 'in:0'];
        }

        return $rules;
    }
}
