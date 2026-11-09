<x-admin::layouts>
    <x-slot:title>
        @lang('google_merchant::app.mapping.attributes-title')
    </x-slot>

    @include('google_merchant::admin.partials.styles')

    @php
        $t = fn ($key, $replace = []) => trans("google_merchant::app.mapping.$key", $replace);
        $input = 'w-full rounded-md border px-3 py-2 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300';
    @endphp

    <div v-pre>
        <form method="POST" action="{{ route('admin.google_merchant.mapping.attributes.update') }}">
            @csrf
            @method('PUT')

            <div class="mb-4 flex items-center justify-between gap-4 max-sm:flex-wrap">
                <div>
                    <p class="text-xl font-bold text-gray-800 dark:text-white">{{ $t('attributes-title') }}</p>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $t('attributes-intro') }}</p>
                </div>

                <div class="flex gap-2">
                    <a href="{{ route('admin.google_merchant.mapping.categories') }}" class="secondary-button">{{ $t('tab-categories') }}</a>

                    @if (bouncer()->hasPermission('marketing.google_merchant.mapping.attributes_update'))
                        <button type="submit" class="primary-button">{{ $t('save') }}</button>
                    @endif
                </div>
            </div>

            @if ($errors->any())
                <div class="mb-4 rounded bg-red-50 px-4 py-3 text-sm text-red-700 dark:bg-gray-800">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                <table class="w-full text-left text-sm text-gray-700 dark:text-gray-300">
                    <thead>
                        <tr class="border-b dark:border-gray-800">
                            <th class="py-2 pr-3">{{ $t('google-field') }}</th>
                            <th class="py-2 pr-3">{{ $t('source') }}</th>
                            <th class="py-2">{{ $t('value') }}</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($fields as $row)
                            @php
                                $field = $row['field'];
                                $type = old("mappings.$field.type", $row['type']);
                                $value = old("mappings.$field.value", $row['value']);
                            @endphp

                            <tr class="border-b align-top dark:border-gray-800">
                                <td class="py-2 pr-3">
                                    <span class="font-medium">{{ $field }}</span>
                                    @if ($row['custom'])
                                        <span class="gmc-badge gmc-info">{{ $t('customised') }}</span>
                                    @endif
                                    <span class="block text-xs text-gray-500">{{ $t('default', ['value' => $row['default'][1]]) }}</span>
                                </td>

                                <td class="py-2 pr-3">
                                    <select name="mappings[{{ $field }}][type]" class="{{ $input }}">
                                        @foreach (['attribute', 'static', 'none'] as $option)
                                            <option value="{{ $option }}" @selected($type === $option)>{{ $t('type-'.$option) }}</option>
                                        @endforeach
                                    </select>
                                </td>

                                <td class="py-2">
                                    <input
                                        name="mappings[{{ $field }}][value]"
                                        value="{{ $value }}"
                                        list="gmc-attribute-codes"
                                        class="{{ $input }}"
                                    >
                                    @error("mappings.$field.value")
                                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                    @enderror
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <datalist id="gmc-attribute-codes">
                    @foreach ($attributes as $attribute)
                        <option value="{{ $attribute->code }}">{{ $attribute->admin_name }} ({{ $attribute->type }})</option>
                    @endforeach
                </datalist>
            </div>
        </form>
    </div>
</x-admin::layouts>
