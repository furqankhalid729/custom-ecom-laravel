{{-- Settings for one selling region. Vars: $code, $definition, $settings. --}}
@php
    $p = "regions.{$code}";
    $methods = old("{$p}.shipping.methods", data_get($settings, "{$p}.shipping.methods", []));
    $methods = array_values(is_array($methods) ? $methods : []);
    $methods[] = ['name' => null, 'carrier' => null, 'cost' => null, 'free_over' => null, 'min_days' => null, 'max_days' => null];
    $t = fn ($key) => trans("store_policy::app.fields.{$key}");
    $o = fn ($key) => trans("store_policy::app.options.{$key}");
    $methodsError = $errors->first("{$p}.shipping.methods");
@endphp

<div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
    <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
        {{ $definition['label'] }} ({{ $definition['currency'] }})
    </p>

    @include('store_policy::admin.partials.field', ['path' => "{$p}.enabled", 'label' => $t('region_enabled'), 'type' => 'checkbox'])

    <input type="hidden" name="regions[{{ $code }}][currency]" value="{{ $definition['currency'] }}">

    @if ($code === 'EU')
        @include('store_policy::admin.partials.field', [
            'path'    => "{$p}.countries",
            'label'   => $t('countries'),
            'type'    => 'multiselect',
            'options' => collect(\Custom\StorePolicy\Support\Regions::EU_COUNTRIES)->mapWithKeys(fn ($c) => [$c => \Custom\StorePolicy\Support\Regions::COUNTRY_NAMES[$c]])->all(),
            'hint'    => 'Hold Ctrl (Cmd on Mac) to select several.',
        ])
    @else
        @foreach ($definition['countries'] as $country)
            <input type="hidden" name="regions[{{ $code }}][countries][]" value="{{ $country }}">
        @endforeach
    @endif

    <p class="mb-3 mt-2 text-sm font-semibold text-gray-800 dark:text-white">@lang('store_policy::app.sections.returns')</p>

    <div class="grid grid-cols-2 gap-x-4 max-md:grid-cols-1">
        @if ($code === 'EU')
            <input type="hidden" name="regions[EU][returns][accepted]" value="1">
        @else
            @include('store_policy::admin.partials.field', ['path' => "{$p}.returns.accepted", 'label' => $t('returns_accepted'), 'type' => 'checkbox'])
            <div></div>
        @endif

        @include('store_policy::admin.partials.field', ['path' => "{$p}.returns.window_days", 'label' => $t('window_days'), 'type' => 'number', 'required' => true, 'hint' => $code === 'EU' ? 'EU law: at least 14 days.' : null])
        @include('store_policy::admin.partials.field', ['path' => "{$p}.returns.refund_days", 'label' => $t('refund_days'), 'type' => 'number', 'required' => true, 'hint' => $code === 'EU' ? 'EU law: at most 14 days.' : null])
        {{-- EU: withdrawal must work at a distance and refunds go to the original means of payment. --}}
        @include('store_policy::admin.partials.field', [
            'path'    => "{$p}.returns.method",
            'label'   => $t('method'),
            'type'    => 'select',
            'options' => $code === 'EU'
                ? ['mail' => $o('mail'), 'both' => $o('both')]
                : ['mail' => $o('mail'), 'store' => $o('store'), 'both' => $o('both')],
        ])
        @include('store_policy::admin.partials.field', ['path' => "{$p}.returns.return_shipping_paid_by", 'label' => $t('return_shipping_paid_by'), 'type' => 'select', 'options' => ['customer' => $o('customer'), 'store' => $o('store_pays'), 'free' => $o('free')]])
        @include('store_policy::admin.partials.field', [
            'path'    => "{$p}.returns.refund_method",
            'label'   => $t('refund_method'),
            'type'    => 'select',
            'options' => $code === 'EU'
                ? ['original' => $o('original'), 'customer_choice' => $o('customer_choice')]
                : ['original' => $o('original'), 'store_credit' => $o('store_credit'), 'customer_choice' => $o('customer_choice')],
        ])

        @if ($code !== 'EU')
            @include('store_policy::admin.partials.field', ['path' => "{$p}.returns.restocking_fee_percent", 'label' => $t('restocking_fee_percent'), 'type' => 'number'])
        @endif

        @if ($code !== 'EU')
            @include('store_policy::admin.partials.field', ['path' => "{$p}.returns.defect_report_days", 'label' => $t('defect_report_days'), 'type' => 'number'])
        @endif
        @include('store_policy::admin.partials.field', ['path' => "{$p}.returns.conditions", 'label' => $t('conditions'), 'type' => 'multiselect', 'options' => ['unused' => $o('unused'), 'original_packaging' => $o('original_packaging'), 'tags_attached' => $o('tags_attached')]])
        @include('store_policy::admin.partials.field', ['path' => "{$p}.returns.exchanges", 'label' => $t('exchanges'), 'type' => 'checkbox'])
    </div>

    @include('store_policy::admin.partials.field', ['path' => "{$p}.returns.conditions_note", 'label' => $t('conditions_note'), 'type' => 'textarea'])
    @include('store_policy::admin.partials.field', ['path' => "{$p}.returns.non_returnable", 'label' => $t('non_returnable'), 'type' => 'textarea'])
    @include('store_policy::admin.partials.field', ['path' => "{$p}.returns.defect_resolution", 'label' => $t('defect_resolution'), 'type' => 'textarea'])

    <p class="mb-3 mt-2 text-sm font-semibold text-gray-800 dark:text-white">@lang('store_policy::app.sections.shipping')</p>

    <div class="grid grid-cols-3 gap-x-4 max-md:grid-cols-1">
        @include('store_policy::admin.partials.field', ['path' => "{$p}.shipping.handling_min_days", 'label' => $t('handling_min_days'), 'type' => 'number', 'required' => true])
        @include('store_policy::admin.partials.field', ['path' => "{$p}.shipping.handling_max_days", 'label' => $t('handling_max_days'), 'type' => 'number', 'required' => true])
        @include('store_policy::admin.partials.field', ['path' => "{$p}.shipping.cutoff_time", 'label' => $t('cutoff_time'), 'type' => 'time'])
    </div>

    <p class="mb-1.5 text-xs font-medium text-gray-800 dark:text-white required">{{ $t('methods') }}</p>
    <p class="mb-2 rounded bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-gray-800 dark:text-amber-300">{{ $t('shipping_notice') }}</p>

    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs text-gray-700 dark:text-gray-300" data-shipping-methods="{{ $code }}">
            <thead>
                <tr>
                    <th class="p-1">{{ $t('method_name') }}</th>
                    <th class="p-1">{{ $t('method_carrier') }}</th>
                    <th class="p-1">{{ $t('method_cost') }}</th>
                    <th class="p-1">{{ $t('method_free_over') }}</th>
                    <th class="p-1">{{ $t('method_min_days') }}</th>
                    <th class="p-1">{{ $t('method_max_days') }}</th>
                    <th class="p-1"></th>
                </tr>
            </thead>

            <tbody>
                @foreach ($methods as $index => $method)
                    <tr>
                        @foreach (['name' => 'text', 'carrier' => 'text', 'cost' => 'number', 'free_over' => 'number', 'min_days' => 'number', 'max_days' => 'number'] as $field => $inputType)
                            <td class="p-1">
                                <input
                                    type="{{ $inputType }}"
                                    @if ($inputType === 'number') min="0" step="{{ in_array($field, ['cost', 'free_over']) ? '0.01' : '1' }}" @endif
                                    name="regions[{{ $code }}][shipping][methods][{{ $index }}][{{ $field }}]"
                                    value="{{ $method[$field] ?? '' }}"
                                    class="w-full min-w-[70px] rounded-md border px-2 py-1.5 text-xs dark:border-gray-800 dark:bg-gray-900 {{ $errors->has("{$p}.shipping.methods.{$index}.{$field}") ? '!border-red-600' : '' }}"
                                >
                            </td>
                        @endforeach

                        <td class="p-1">
                            <button type="button" class="text-red-600 hover:underline" data-remove-method>{{ $t('remove_method') }}</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <button type="button" class="secondary-button mt-2 mb-4" data-add-method="{{ $code }}">{{ $t('add_method') }}</button>

    @if ($methodsError)
        <p class="mb-3 text-xs italic text-red-600">{{ $methodsError }}</p>
    @endif

    @include('store_policy::admin.partials.field', ['path' => "{$p}.shipping.tracking", 'label' => $t('tracking'), 'type' => 'checkbox'])
    @include('store_policy::admin.partials.field', ['path' => "{$p}.shipping.po_boxes", 'label' => $t('po_boxes'), 'type' => 'checkbox'])
    @include('store_policy::admin.partials.field', ['path' => "{$p}.shipping.excluded_areas", 'label' => $t('excluded_areas'), 'type' => 'textarea'])
    @include('store_policy::admin.partials.field', ['path' => "{$p}.shipping.lost_damaged_note", 'label' => $t('lost_damaged_note'), 'type' => 'textarea'])
</div>
