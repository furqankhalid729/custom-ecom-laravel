<x-admin::layouts>
    <x-slot:title>
        @lang('google_merchant::app.targets.title')
    </x-slot>

    @include('google_merchant::admin.partials.styles')

    @php
        $t = fn ($key, $replace = []) => trans("google_merchant::app.targets.$key", $replace);
        $input = 'w-full rounded-md border px-3 py-2 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300';
        $label = 'mb-1 block text-sm font-medium text-gray-800 dark:text-white';
    @endphp

    <div v-pre>
        <div class="mb-4">
            <p class="text-xl font-bold text-gray-800 dark:text-white">{{ $t('title') }}</p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $t('intro') }}</p>
        </div>

        @if ($errors->any())
            <div class="mb-4 rounded bg-red-50 px-4 py-3 text-sm text-red-700 dark:bg-gray-800">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <div class="flex gap-2.5 max-xl:flex-wrap">
            <div class="box-shadow flex-1 overflow-x-auto rounded bg-white p-4 dark:bg-gray-900">
                @if ($targets->isEmpty())
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ $t('empty') }}</p>
                @else
                    <table class="w-full text-left text-sm text-gray-700 dark:text-gray-300">
                        <thead>
                            <tr class="border-b dark:border-gray-800">
                                <th class="py-2 pr-3">{{ $t('feed-label') }}</th>
                                <th class="py-2 pr-3">{{ $t('countries') }}</th>
                                <th class="py-2 pr-3">{{ $t('language') }}</th>
                                <th class="py-2 pr-3">{{ $t('currency') }}</th>
                                <th class="py-2 pr-3">{{ $t('products') }}</th>
                                <th class="py-2 pr-3">{{ $t('status') }}</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($targets as $target)
                                <tr class="border-b dark:border-gray-800">
                                    <td class="py-2 pr-3 font-medium">
                                        <a href="{{ route('admin.google_merchant.overview', ['target' => $target->id]) }}" class="text-blue-600 hover:underline">{{ $target->feed_label }}</a>
                                    </td>
                                    <td class="py-2 pr-3">{{ implode(', ', $target->countries) }}</td>
                                    <td class="py-2 pr-3">{{ $target->content_language }}</td>
                                    <td class="py-2 pr-3">{{ $target->currency }}</td>
                                    <td class="py-2 pr-3">{{ $counts[$target->id] ?? 0 }}</td>
                                    <td class="py-2 pr-3">
                                        <span class="gmc-badge {{ $target->enabled ? 'gmc-ok' : 'gmc-muted' }}">{{ $target->enabled ? $t('active') : $t('paused') }}</span>
                                    </td>
                                    <td class="flex justify-end gap-2 py-2">
                                        @if (bouncer()->hasPermission('marketing.google_merchant.targets.update'))
                                            <form method="POST" action="{{ route('admin.google_merchant.targets.update', $target->id) }}">
                                                @csrf
                                                @method('PUT')
                                                <input type="hidden" name="enabled" value="{{ $target->enabled ? 0 : 1 }}">
                                                <button type="submit" class="secondary-button">{{ $target->enabled ? $t('pause') : $t('resume') }}</button>
                                            </form>
                                        @endif

                                        @if (bouncer()->hasPermission('marketing.google_merchant.targets.delete'))
                                            <form method="POST" action="{{ route('admin.google_merchant.targets.destroy', $target->id) }}" onsubmit="return confirm(@js($t('remove-confirm')))">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="transparent-button text-red-600">{{ $t('remove') }}</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            @if ($account && bouncer()->hasPermission('marketing.google_merchant.targets.create'))
                <div class="gmc-side box-shadow rounded bg-white p-4 dark:bg-gray-900">
                    <p class="mb-3 text-base font-semibold text-gray-800 dark:text-white">{{ $t('add') }}</p>

                    <form method="POST" action="{{ route('admin.google_merchant.targets.store') }}">
                        @csrf

                        <label class="{{ $label }}">{{ $t('countries') }}</label>
                        <input name="countries" value="{{ old('countries') && is_string(old('countries')) ? old('countries') : '' }}" placeholder="DE, AT" class="{{ $input }}">
                        <p class="mb-3 mt-1 text-xs text-gray-600 dark:text-gray-400">{{ $t('countries-help') }}</p>

                        <label class="{{ $label }}">{{ $t('language') }}</label>
                        <select name="locale" class="{{ $input }} mb-3">
                            @foreach ($locales as $code)
                                <option value="{{ $code }}" @selected(old('locale') === $code)>{{ $code }}</option>
                            @endforeach
                        </select>

                        <label class="{{ $label }}">{{ $t('currency') }}</label>
                        <select name="currency" class="{{ $input }} mb-3">
                            @foreach ($currencies as $code)
                                <option value="{{ $code }}" @selected(old('currency') === $code)>{{ $code }}</option>
                            @endforeach
                        </select>

                        <label class="{{ $label }}">{{ $t('feed-label') }}</label>
                        <input name="feed_label" value="{{ old('feed_label') }}" maxlength="20" class="{{ $input }}">
                        <p class="mb-3 mt-1 text-xs text-gray-600 dark:text-gray-400">{{ $t('feed-label-help') }}</p>

                        <button type="submit" class="primary-button">{{ $t('add') }}</button>
                    </form>
                </div>
            @endif
        </div>
    </div>
</x-admin::layouts>
