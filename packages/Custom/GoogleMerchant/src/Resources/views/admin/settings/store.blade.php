<x-admin::layouts>
    <x-slot:title>
        @lang('google_merchant::app.store.title')
    </x-slot>

    @include('google_merchant::admin.partials.styles')

    @php
        $t = fn ($key, $replace = []) => trans("google_merchant::app.store.$key", $replace);
        $input = 'w-full rounded-md border px-3 py-2 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300';
        $value = fn ($field) => old($field, $values[$field] ?? '');
        $label = 'mb-1 block text-sm font-medium text-gray-800 dark:text-white';
    @endphp

    <div v-pre>
        <form method="POST" action="{{ route('admin.google_merchant.store.update') }}">
            @csrf
            @method('PUT')

            <div class="mb-4 flex items-center justify-between gap-4 max-sm:flex-wrap">
                <div>
                    <p class="text-xl font-bold text-gray-800 dark:text-white">{{ $t('title') }}</p>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $t('intro') }}</p>
                </div>

                @if (bouncer()->hasPermission('marketing.google_merchant.store.update'))
                    <button type="submit" class="primary-button">{{ $t('save') }}</button>
                @endif
            </div>

            @if ($errors->any())
                <div class="mb-4 rounded bg-red-50 px-4 py-3 text-sm text-red-700 dark:bg-gray-800">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <div class="flex gap-2.5 max-xl:flex-wrap">
                <div class="flex flex-1 flex-col gap-2 max-xl:flex-auto">
                    <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                        <p class="mb-3 text-base font-semibold text-gray-800 dark:text-white">{{ $t('market') }}</p>

                        <div class="grid grid-cols-3 gap-3 max-md:grid-cols-1">
                            <div>
                                <label class="{{ $label }}">{{ $t('country') }}</label>
                                <input name="country" value="{{ $value('country') ?: 'US' }}" maxlength="2" class="{{ $input }}">
                            </div>
                            <div>
                                <label class="{{ $label }}">{{ $t('language') }}</label>
                                <select name="language" class="{{ $input }}">
                                    @foreach ($locales as $code)
                                        <option value="{{ $code }}" @selected(($value('language') ?: 'en') === $code)>{{ $code }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="{{ $label }}">{{ $t('currency') }}</label>
                                <select name="currency" class="{{ $input }}">
                                    @foreach ($currencies as $code)
                                        <option value="{{ $code }}" @selected(($value('currency') ?: 'USD') === $code)>{{ $code }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        @if ($target)
                            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                                {{ $t('current-market', ['countries' => implode(', ', $target->countries), 'language' => $target->content_language, 'currency' => $target->currency]) }}
                            </p>
                        @endif

                        <label class="mt-3 flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="hidden" name="confirm_resync" value="0">
                            <input type="checkbox" name="confirm_resync" value="1" @checked(old('confirm_resync'))>
                            <span>{{ $t('confirm-resync') }}</span>
                        </label>
                    </div>

                    <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                        <p class="mb-3 text-base font-semibold text-gray-800 dark:text-white">{{ $t('urls') }}</p>

                        @foreach (['website_url', 'return_policy_url', 'refund_policy_url', 'terms_url', 'privacy_url'] as $field)
                            <label class="{{ $label }}">{{ $t($field) }}</label>
                            <input name="{{ $field }}" type="url" value="{{ $value($field) }}" placeholder="https://" class="{{ $input }} mb-3">
                        @endforeach

                        <p class="text-xs text-gray-600 dark:text-gray-400">{{ $t('urls-help') }}</p>
                    </div>

                    <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                        <label class="{{ $label }}">{{ $t('shipping_note') }}</label>
                        <textarea name="shipping_note" rows="3" class="{{ $input }} mb-3">{{ $value('shipping_note') }}</textarea>

                        <label class="{{ $label }}">{{ $t('tax_note') }}</label>
                        <textarea name="tax_note" rows="3" class="{{ $input }}">{{ $value('tax_note') }}</textarea>
                    </div>
                </div>

                <div class="gmc-side flex flex-col gap-2">
                    <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                        <p class="mb-3 text-base font-semibold text-gray-800 dark:text-white">{{ $t('url-report') }}</p>

                        @forelse ($report as $row)
                            <div class="mb-2 text-sm">
                                <span class="gmc-badge {{ $row['ok'] ? 'gmc-ok' : 'gmc-bad' }}">{{ $row['ok'] ? '✓' : '!' }}</span>
                                <span class="font-medium text-gray-800 dark:text-white">{{ $t($row['field']) }}</span>
                                <span class="block break-all text-gray-600 dark:text-gray-400">{{ $row['url'] }} — {{ $row['message'] }}</span>
                            </div>
                        @empty
                            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $t('url-report-empty') }}</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </form>
    </div>
</x-admin::layouts>
