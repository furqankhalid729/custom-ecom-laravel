<x-admin::layouts>
    <x-slot:title>
        @lang('google_merchant::app.logs.title')
    </x-slot>

    @include('google_merchant::admin.partials.styles')

    <div class="mb-4">
        <p class="text-xl font-bold text-gray-800 dark:text-white">@lang('google_merchant::app.logs.title')</p>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">@lang('google_merchant::app.logs.intro')</p>
    </div>

    <x-admin::datagrid :src="route('admin.google_merchant.logs.index')" />
</x-admin::layouts>
