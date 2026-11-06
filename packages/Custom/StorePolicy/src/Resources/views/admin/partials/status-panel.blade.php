{{-- Policy pages with their status, plus Generate / Restore. Vars: $statuses, $outOfDate. --}}
@php
    $badge = [
        'not_generated'      => 'bg-gray-100 text-gray-700',
        'up_to_date'         => 'bg-green-100 text-green-700',
        'out_of_date'        => 'bg-amber-100 text-amber-800',
        'edited'             => 'bg-blue-100 text-blue-700',
        'out_of_date_edited' => 'bg-red-100 text-red-700',
    ];
@endphp

<form method="POST" action="{{ route('admin.cms.store_policies.generate') }}" class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
    @csrf

    <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">@lang('store_policy::app.status.title')</p>

    @if ($outOfDate)
        <p class="mb-3 rounded bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-gray-800 dark:text-amber-300">
            @lang('store_policy::app.index.out-of-date')
            {{ implode(', ', array_map(fn ($key) => $statuses[$key]['title'] ?? $key, $outOfDate)) }}
        </p>
    @endif

    <div class="grid gap-3">
        @foreach ($statuses as $key => $status)
            @php($state = $status['status']->value)

            <div class="rounded border p-3 dark:border-gray-800">
                <div class="flex items-start justify-between gap-2">
                    <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-gray-800 dark:text-white">
                        <input
                            type="checkbox"
                            name="templates[]"
                            value="{{ $key }}"
                            @checked(in_array($key, $outOfDate, true) || $state === 'not_generated')
                        >

                        {{ $status['title'] }}
                    </label>

                    <span class="whitespace-nowrap rounded px-2 py-0.5 text-[11px] font-semibold {{ $badge[$state] }}">
                        @lang("store_policy::app.status.{$state}")
                    </span>
                </div>

                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    @lang('store_policy::app.status.generated-at'):
                    {{ $status['generated_at'] ? $status['generated_at']->format('Y-m-d H:i') : trans('store_policy::app.status.never') }}

                    @if ($status['page_id'])
                        · <a href="{{ route('shop.cms.page', $status['url_key']) }}" target="_blank" class="text-blue-600 hover:underline">@lang('store_policy::app.status.view')</a>
                        · <a href="{{ route('admin.cms.edit', $status['page_id']) }}" class="text-blue-600 hover:underline">@lang('store_policy::app.status.edit')</a>
                    @endif
                </p>

                @if ($status['status']->needsConfirmation())
                    <label class="mt-2 flex cursor-pointer items-center gap-2 text-xs text-red-700">
                        <input type="checkbox" name="confirm_overwrite[]" value="{{ $key }}">

                        @lang('store_policy::app.status.confirm-overwrite')
                    </label>
                @endif

                @if ($status['can_restore'])
                    <button
                        type="submit"
                        formaction="{{ route('admin.cms.store_policies.restore', $key) }}"
                        class="mt-2 text-xs text-blue-600 hover:underline"
                    >
                        @lang('store_policy::app.status.restore')
                    </button>
                @endif
            </div>
        @endforeach
    </div>

    @if ($errors->has('templates'))
        <p class="mt-2 text-xs italic text-red-600">{{ $errors->first('templates') }}</p>
    @endif

    <button type="submit" class="primary-button mt-4 w-full justify-center">
        @lang('store_policy::app.status.generate-btn')
    </button>
</form>
