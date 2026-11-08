<x-admin::layouts>
    <x-slot:title>
        @lang('google_merchant::app.runs.title')
    </x-slot>

    @include('google_merchant::admin.partials.styles')

    @php
        $t = fn ($key, $replace = []) => trans("google_merchant::app.runs.$key", $replace);
        $input = 'w-full rounded-md border px-3 py-2 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300';
        $flatten = function ($nodes, $depth = 0) use (&$flatten) {
            $out = [];
            foreach ($nodes as $node) {
                $out[] = [$node->id, str_repeat('— ', $depth).$node->name];
                $out = array_merge($out, $flatten($node->children ?? [], $depth + 1));
            }
            return $out;
        };
    @endphp

    <div v-pre>
        <div class="mb-4">
            <p class="text-xl font-bold text-gray-800 dark:text-white">{{ $t('title') }}</p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $t('intro') }}</p>
        </div>

        <div class="flex gap-2.5 max-xl:flex-wrap">
            <div class="flex flex-1 flex-col gap-2 max-xl:flex-auto">
                <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                    <p class="mb-3 text-base font-semibold text-gray-800 dark:text-white">{{ $t('history') }}</p>

                    @if ($runs->isEmpty())
                        <p class="text-sm text-gray-600 dark:text-gray-300">{{ $t('no-runs') }}</p>
                    @else
                        <table class="w-full text-left text-sm text-gray-700 dark:text-gray-300">
                            <thead>
                                <tr class="border-b dark:border-gray-800">
                                    <th class="py-2 pr-3">#</th>
                                    <th class="py-2 pr-3">{{ $t('type') }}</th>
                                    <th class="py-2 pr-3">{{ $t('progress') }}</th>
                                    <th class="py-2 pr-3">{{ $t('result') }}</th>
                                    <th class="py-2 pr-3">{{ $t('started') }}</th>
                                    <th class="py-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($runs as $run)
                                    <tr class="border-b dark:border-gray-800" data-gmc-run="{{ $run->status === 'running' ? route('admin.google_merchant.runs.show', $run->id) : '' }}">
                                        <td class="py-2 pr-3">{{ $run->id }}</td>
                                        <td class="py-2 pr-3">{{ $t('type-'.$run->type) }} {{ isset($run->options['mode']) && $run->type === 'bulk' ? '('.$t('mode-'.$run->options['mode']).')' : '' }}</td>
                                        <td class="py-2 pr-3">
                                            <span data-field="processed">{{ $run->processed }}</span> / <span data-field="total">{{ $run->total }}</span>
                                            <span class="gmc-badge {{ ['running' => 'gmc-info', 'completed' => 'gmc-ok', 'paused' => 'gmc-warn', 'failed' => 'gmc-bad'][$run->status] ?? 'gmc-muted' }}" data-field="status">{{ $t('status-'.$run->status) }}</span>
                                        </td>
                                        <td class="py-2 pr-3">
                                            ✓ <span data-field="succeeded">{{ $run->succeeded }}</span>
                                            &nbsp; ✗ <span data-field="failed">{{ $run->failed }}</span>
                                        </td>
                                        <td class="py-2 pr-3">{{ $run->started_at ? core()->formatDate($run->started_at, 'd M Y H:i') : '—' }}</td>
                                        <td class="py-2">
                                            @if (in_array($run->status, ['running', 'paused']) && bouncer()->hasPermission('marketing.google_merchant.runs.pause'))
                                                <form method="POST" action="{{ route('admin.google_merchant.runs.'.($run->status === 'running' ? 'pause' : 'resume'), $run->id) }}">
                                                    @csrf
                                                    <button class="secondary-button" type="submit">{{ $t($run->status === 'running' ? 'pause' : 'resume') }}</button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>

            <div class="gmc-side flex flex-col gap-2">
                @if (bouncer()->hasPermission('marketing.google_merchant.runs.create'))
                    <form method="POST" action="{{ route('admin.google_merchant.runs.store') }}" class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                        @csrf

                        <p class="mb-3 text-base font-semibold text-gray-800 dark:text-white">{{ $t('start') }}</p>

                        <label class="mb-2 flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="radio" name="mode" value="incremental" checked>
                            <span><b>{{ $t('mode-incremental') }}</b><br>{{ $t('mode-incremental-help') }}</span>
                        </label>
                        <label class="mb-4 flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="radio" name="mode" value="full">
                            <span><b>{{ $t('mode-full') }}</b><br>{{ $t('mode-full-help') }}</span>
                        </label>

                        <p class="mb-2 text-sm font-semibold text-gray-800 dark:text-white">{{ $t('filters') }}</p>

                        <label class="mb-1 block text-xs text-gray-600 dark:text-gray-300">{{ $t('filter-categories') }}</label>
                        <select name="category_ids[]" multiple size="5" class="{{ $input }} mb-3">
                            @foreach ($flatten($categories) as [$id, $name])
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>

                        <label class="mb-1 block text-xs text-gray-600 dark:text-gray-300">{{ $t('filter-status') }}</label>
                        <select name="product_status" class="{{ $input }} mb-3">
                            <option value="any">{{ $t('status-any') }}</option>
                            <option value="enabled">{{ $t('status-enabled') }}</option>
                            <option value="disabled">{{ $t('status-disabled') }}</option>
                        </select>

                        <label class="mb-1 block text-xs text-gray-600 dark:text-gray-300">{{ $t('filter-updated') }}</label>
                        <div class="mb-3 flex gap-2">
                            <input type="date" name="updated_from" class="{{ $input }}">
                            <input type="date" name="updated_to" class="{{ $input }}">
                        </div>

                        <label class="mb-1 block text-xs text-gray-600 dark:text-gray-300">{{ $t('filter-ids') }}</label>
                        <input type="text" name="product_ids" placeholder="12, 15, 40" class="{{ $input }} mb-4">

                        @error('mode')
                            <p class="mb-2 text-xs text-red-600">{{ $message }}</p>
                        @enderror

                        <button type="submit" class="primary-button w-full">{{ $t('start-button') }}</button>
                    </form>
                @endif

                @if ($account?->isConnected() && bouncer()->hasPermission('marketing.google_merchant.runs.delete_all'))
                    <form method="POST" action="{{ route('admin.google_merchant.runs.delete_all') }}" onsubmit="return confirm(@js($t('delete-all-confirm')))" class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                        @csrf

                        <p class="mb-1 text-base font-semibold text-gray-800 dark:text-white">{{ $t('delete-all') }}</p>
                        <p class="mb-3 text-sm text-gray-600 dark:text-gray-300">{{ $t('delete-all-help') }}</p>

                        <button type="submit" class="secondary-button">{{ $t('delete-all-button') }}</button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    @pushOnce('scripts')
        <script>
            (function () {
                function poll() {
                    var rows = document.querySelectorAll('tr[data-gmc-run]:not([data-gmc-run=""])');

                    rows.forEach(function (row) {
                        fetch(row.getAttribute('data-gmc-run'), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                            .then(function (r) { return r.json(); })
                            .then(function (data) {
                                ['processed', 'total', 'succeeded', 'failed'].forEach(function (f) {
                                    row.querySelector('[data-field="' + f + '"]').textContent = data[f];
                                });

                                if (data.status !== 'running') {
                                    window.location.reload();
                                }
                            })
                            .catch(function () {});
                    });

                    if (rows.length) {
                        setTimeout(poll, 5000);
                    }
                }

                setTimeout(poll, 5000);
            })();
        </script>
    @endPushOnce
</x-admin::layouts>
