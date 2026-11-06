{{-- Merchant Center readiness checklist. Vars: $readiness. --}}
<div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
    <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">@lang('store_policy::app.readiness.title')</p>

    <ul class="grid gap-2">
        @foreach ($readiness as $item)
            <li class="flex gap-2 text-sm">
                <span class="{{ $item['passed'] ? 'text-green-600' : 'text-red-600' }} font-bold" aria-hidden="true">{{ $item['passed'] ? '✓' : '✗' }}</span>

                <span>
                    <span class="text-gray-800 dark:text-white">{{ $item['label'] }}</span>

                    @if ($item['detail'])
                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $item['detail'] }}</span>
                    @endif
                </span>
            </li>
        @endforeach
    </ul>
</div>
