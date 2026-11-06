<x-admin::layouts>
    <x-slot:title>
        @lang('store_policy::app.index.title')
    </x-slot>

    @php
        $t = fn ($key) => trans("store_policy::app.fields.{$key}");
        $o = fn ($key) => trans("store_policy::app.options.{$key}");
        $field = 'store_policy::admin.partials.field';
    @endphp

    {{-- Plain HTML forms: v-pre keeps Vue from compiling user-entered text. --}}
    <div v-pre>
        <div class="mb-4 flex items-center justify-between gap-4 max-sm:flex-wrap">
            <div>
                <p class="text-xl font-bold text-gray-800 dark:text-white">@lang('store_policy::app.index.title')</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">@lang('store_policy::app.index.intro')</p>
            </div>

            <button type="submit" form="store-policy-settings" class="primary-button">
                @lang('store_policy::app.index.save-btn')
            </button>
        </div>

        @unless ($saved)
            <p class="mb-4 rounded bg-blue-50 px-3 py-2 text-sm text-blue-800 dark:bg-gray-800 dark:text-blue-300">@lang('store_policy::app.index.not-saved')</p>
        @endunless

        <div class="flex gap-2.5 max-xl:flex-wrap">
            <!-- Settings -->
            <form
                id="store-policy-settings"
                method="POST"
                action="{{ route('admin.cms.store_policies.update') }}"
                class="flex flex-1 flex-col gap-2 max-xl:flex-auto"
            >
                @csrf
                @method('PUT')

                <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                    <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">@lang('store_policy::app.sections.business')</p>

                    <div class="grid grid-cols-2 gap-x-4 max-md:grid-cols-1">
                        @include($field, ['path' => 'business.legal_name', 'label' => $t('legal_name'), 'required' => true])
                        @include($field, ['path' => 'business.trading_name', 'label' => $t('trading_name')])
                        @include($field, ['path' => 'business.address.street', 'label' => $t('street'), 'required' => true])
                        @include($field, ['path' => 'business.address.city', 'label' => $t('city'), 'required' => true])
                        @include($field, ['path' => 'business.address.region', 'label' => $t('region')])
                        @include($field, ['path' => 'business.address.postcode', 'label' => $t('postcode'), 'required' => true])
                        @include($field, ['path' => 'business.address.country', 'label' => $t('country'), 'required' => true])
                        @include($field, ['path' => 'business.email', 'label' => $t('email'), 'type' => 'email', 'required' => true])
                        @include($field, ['path' => 'business.phone', 'label' => $t('phone'), 'required' => true])
                        @include($field, ['path' => 'business.hours', 'label' => $t('hours')])
                        @include($field, ['path' => 'business.registration_number', 'label' => $t('registration_number')])
                        @include($field, ['path' => 'business.vat_number', 'label' => $t('vat_number')])
                        @include($field, ['path' => 'business.website_url', 'label' => $t('website_url')])
                        @include($field, ['path' => 'business.logo_url', 'label' => $t('logo_url')])
                    </div>
                </div>

                @foreach ($regionDefs as $code => $definition)
                    @include('store_policy::admin.partials.region', ['code' => $code, 'definition' => $definition])
                @endforeach

                <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                    <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">@lang('store_policy::app.sections.payment')</p>

                    @include($field, ['path' => 'payment.methods', 'label' => $t('payment_methods'), 'type' => 'list'])
                    @include($field, ['path' => 'payment.charged_at', 'label' => $t('charged_at'), 'type' => 'select', 'options' => ['order' => $o('order'), 'shipment' => $o('shipment')]])
                </div>

                <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                    <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">@lang('store_policy::app.sections.privacy')</p>

                    @include($field, ['path' => 'privacy.data_collected', 'label' => $t('data_collected'), 'type' => 'list'])
                    @include($field, ['path' => 'privacy.third_parties', 'label' => $t('third_parties'), 'type' => 'list'])
                    @include($field, ['path' => 'privacy.uses_cookies', 'label' => $t('uses_cookies'), 'type' => 'checkbox'])
                    @include($field, ['path' => 'privacy.sells_or_shares', 'label' => $t('sells_or_shares'), 'type' => 'checkbox', 'hint' => $t('sells_or_shares_hint')])
                    @include($field, ['path' => 'privacy.retention', 'label' => $t('retention'), 'type' => 'textarea'])
                    @include($field, ['path' => 'privacy.contact_email', 'label' => $t('contact_email'), 'type' => 'email'])
                    @include($field, ['path' => 'privacy.eu_representative', 'label' => $t('eu_representative'), 'type' => 'textarea'])
                </div>

                <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                    <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">@lang('store_policy::app.sections.terms')</p>

                    <div class="grid grid-cols-3 gap-x-4 max-md:grid-cols-1">
                        @include($field, ['path' => 'terms.governing_law', 'label' => $t('governing_law'), 'required' => true])
                        @include($field, ['path' => 'terms.minimum_age', 'label' => $t('minimum_age'), 'type' => 'number'])
                        @include($field, ['path' => 'terms.effective_date', 'label' => $t('effective_date'), 'type' => 'date'])
                    </div>
                </div>

                <p class="px-1 text-xs text-gray-500 dark:text-gray-400">@lang('store_policy::app.index.disclaimer')</p>

                <div>
                    <button type="submit" class="primary-button">@lang('store_policy::app.index.save-btn')</button>
                </div>
            </form>

            <!-- Pages + readiness -->
            <div class="flex w-[400px] max-w-full flex-col gap-2 max-xl:w-full">
                @include('store_policy::admin.partials.status-panel')

                @include('store_policy::admin.partials.readiness')
            </div>
        </div>
    </div>

    @pushOnce('scripts')
        <script>
            (function () {
                document.addEventListener('click', function (event) {
                    var add = event.target.closest('[data-add-method]');

                    if (add) {
                        var tbody = document.querySelector('[data-shipping-methods="' + add.dataset.addMethod + '"] tbody');
                        var last = tbody.querySelector('tr:last-child');
                        var row = last.cloneNode(true);

                        // Next index = highest index in use + 1, so a removed row's index is never reused.
                        var index = 0;

                        tbody.querySelectorAll('input[name*="[methods]["]').forEach(function (input) {
                            var match = input.name.match(/\[methods\]\[(\d+)\]/);

                            if (match) {
                                index = Math.max(index, parseInt(match[1], 10) + 1);
                            }
                        });

                        row.querySelectorAll('input').forEach(function (input) {
                            input.value = '';
                            input.name = input.name.replace(/\[methods\]\[\d+\]/, '[methods][' + index + ']');
                            input.classList.remove('!border-red-600');
                        });

                        tbody.appendChild(row);

                        return;
                    }

                    var remove = event.target.closest('[data-remove-method]');

                    if (remove) {
                        var tr = remove.closest('tr');

                        if (tr.parentNode.querySelectorAll('tr').length > 1) {
                            tr.remove();
                        } else {
                            tr.querySelectorAll('input').forEach(function (input) { input.value = ''; });
                        }
                    }
                });
            })();
        </script>
    @endPushOnce
</x-admin::layouts>
