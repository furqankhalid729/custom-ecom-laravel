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

    <x-admin::datagrid :src="route('admin.google_merchant.products.index')" />
</x-admin::layouts>
