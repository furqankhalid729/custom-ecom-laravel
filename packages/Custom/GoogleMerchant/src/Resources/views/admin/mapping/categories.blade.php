<x-admin::layouts>
    <x-slot:title>
        @lang('google_merchant::app.mapping.categories-title')
    </x-slot>

    @include('google_merchant::admin.partials.styles')

    @php
        $t = fn ($key, $replace = []) => trans("google_merchant::app.mapping.$key", $replace);
        $input = 'w-full rounded-md border px-3 py-2 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300';
        $flatten = function ($nodes, $depth = 0) use (&$flatten) {
            $out = [];
            foreach ($nodes as $node) {
                $out[] = [$node, $depth];
                $out = array_merge($out, $flatten($node->children ?? [], $depth + 1));
            }
            return $out;
        };
    @endphp

    <div v-pre>
        <form method="POST" action="{{ route('admin.google_merchant.mapping.categories.update') }}">
            @csrf
            @method('PUT')

            <div class="mb-4 flex items-center justify-between gap-4 max-sm:flex-wrap">
                <div>
                    <p class="text-xl font-bold text-gray-800 dark:text-white">{{ $t('categories-title') }}</p>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $t('categories-intro') }}</p>
                </div>

                <div class="flex gap-2">
                    <a href="{{ route('admin.google_merchant.mapping.attributes') }}" class="secondary-button">{{ $t('tab-fields') }}</a>

                    @if (bouncer()->hasPermission('marketing.google_merchant.mapping.categories_update'))
                        <button type="submit" class="primary-button">{{ $t('save') }}</button>
                    @endif
                </div>
            </div>

            @unless ($taxonomy->available())
                <p class="mb-4 rounded bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-gray-800">{{ $t('no-taxonomy') }}</p>
            @endunless

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
                            <th class="py-2 pr-3">{{ $t('category') }}</th>
                            <th class="py-2 pr-3">{{ $t('google-category') }}</th>
                            <th class="py-2">{{ $t('exclude') }}</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($flatten($tree) as [$category, $depth])
                            @php
                                $mapping = $mappings[$category->id] ?? null;
                                $google = old("categories.{$category->id}.google_product_category", $mapping?->google_product_category);
                            @endphp

                            <tr class="border-b align-top dark:border-gray-800">
                                <td class="py-2 pr-3">{{ str_repeat('— ', $depth) }}{{ $category->name }}</td>

                                <td class="py-2 pr-3">
                                    <input
                                        name="categories[{{ $category->id }}][google_product_category]"
                                        value="{{ $google }}"
                                        list="gmc-taxonomy-{{ $category->id }}"
                                        placeholder="{{ $t('search-placeholder') }}"
                                        data-gmc-taxonomy="gmc-taxonomy-{{ $category->id }}"
                                        class="{{ $input }}"
                                        autocomplete="off"
                                    >
                                    <datalist id="gmc-taxonomy-{{ $category->id }}"></datalist>

                                    @if ($google && ($path = $taxonomy->pathFor($google)))
                                        <span class="mt-1 block text-xs text-gray-500">{{ $path }}</span>
                                    @endif

                                    @error("categories.{$category->id}.google_product_category")
                                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                    @enderror
                                </td>

                                <td class="py-2">
                                    <input type="hidden" name="categories[{{ $category->id }}][excluded]" value="0">
                                    <input type="checkbox" name="categories[{{ $category->id }}][excluded]" value="1" @checked(old("categories.{$category->id}.excluded", $mapping?->excluded))>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </form>
    </div>

    @if ($taxonomy->available())
        @pushOnce('scripts')
            <script>
                (function () {
                    var url = @json(route('admin.google_merchant.mapping.taxonomy'));
                    var timer;

                    document.addEventListener('input', function (event) {
                        var input = event.target;
                        var listId = input.getAttribute && input.getAttribute('data-gmc-taxonomy');

                        if (! listId || input.value.trim().length < 2 || /^\d+$/.test(input.value.trim())) {
                            return;
                        }

                        clearTimeout(timer);

                        timer = setTimeout(function () {
                            fetch(url + '?q=' + encodeURIComponent(input.value.trim()), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                                .then(function (r) { return r.json(); })
                                .then(function (items) {
                                    var list = document.getElementById(listId);
                                    list.innerHTML = '';

                                    items.forEach(function (item) {
                                        var option = document.createElement('option');
                                        option.value = item.id;
                                        option.textContent = item.path;
                                        list.appendChild(option);
                                    });
                                })
                                .catch(function () {});
                        }, 250);
                    });
                })();
            </script>
        @endPushOnce
    @endif
</x-admin::layouts>
