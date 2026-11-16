{{-- Market picker for per-market pages; hidden while the store has a single market. --}}
@if (($targets ?? collect())->count() > 1)
    <form method="GET" class="flex items-center gap-2">
        <label for="gmc-market" class="text-sm text-gray-600 dark:text-gray-300">@lang('google_merchant::app.targets.market')</label>
        <select id="gmc-market" name="target" onchange="this.form.submit()" class="rounded-md border px-2 py-1.5 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            @foreach ($targets as $option)
                <option value="{{ $option->id }}" @selected($target?->id === $option->id)>
                    {{ $option->feed_label }} · {{ $option->content_language }} · {{ $option->currency }}{{ $option->enabled ? '' : ' ('.trans('google_merchant::app.targets.paused').')' }}
                </option>
            @endforeach
        </select>
    </form>
@endif
