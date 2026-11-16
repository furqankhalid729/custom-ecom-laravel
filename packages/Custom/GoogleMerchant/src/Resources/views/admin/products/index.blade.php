<x-admin::layouts>
    <x-slot:title>
        @lang('google_merchant::app.products.title')
    </x-slot>

    @include('google_merchant::admin.partials.styles')

    <div class="mb-4 flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div>
            <p class="text-xl font-bold text-gray-800 dark:text-white">@lang('google_merchant::app.products.title')</p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">@lang('google_merchant::app.products.intro')</p>
        </div>

        <div class="flex gap-2">
            @if (bouncer()->hasPermission('marketing.google_merchant.runs'))
                <a href="{{ route('admin.google_merchant.runs.index') }}" class="primary-button">@lang('google_merchant::app.products.bulk-sync')</a>
            @endif
        </div>
    </div>

    @php($preset = array_filter(request()->only(['issue', 'state']), 'is_string'))

    @if ($preset)
        <div class="mb-3 flex items-center gap-3 rounded border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800 dark:border-blue-900 dark:bg-gray-900 dark:text-blue-300">
            <span>@lang('google_merchant::app.products.preset', ['filter' => implode(', ', $preset)])</span>
            <a href="{{ route('admin.google_merchant.products.index') }}" class="font-semibold underline">@lang('google_merchant::app.products.clear-preset')</a>
        </div>
    @endif

    <x-admin::datagrid :src="route('admin.google_merchant.products.index', $preset)" />
</x-admin::layouts>
