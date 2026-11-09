<x-admin::layouts>
    <x-slot:title>
        @lang('google_merchant::app.logs.detail-title', ['id' => $log->id])
    </x-slot>

    @include('google_merchant::admin.partials.styles')

    <div v-pre>
        <div class="mb-4 flex items-center justify-between gap-4">
            <p class="text-xl font-bold text-gray-800 dark:text-white">@lang('google_merchant::app.logs.detail-title', ['id' => $log->id])</p>
            <a href="{{ route('admin.google_merchant.logs.index') }}" class="secondary-button">@lang('google_merchant::app.logs.back')</a>
        </div>

        <div class="flex gap-2.5 max-xl:flex-wrap">
            <div class="flex flex-1 flex-col gap-2">
                @if ($log->status === 'error')
                    <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                        <p class="text-base font-semibold text-gray-800 dark:text-white">{{ $explanation['title'] }}</p>
                        <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $explanation['hint'] }}</p>
                    </div>
                @endif

                <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                    <dl class="grid grid-cols-2 gap-2 text-sm text-gray-700 dark:text-gray-300">
                        @foreach (['created_at' => 'time', 'product_id' => 'product-id', 'offer_id' => 'offer-id', 'operation' => 'operation', 'status' => 'status', 'http_status' => 'http', 'attempt' => 'attempt', 'duration_ms' => 'duration'] as $field => $label)
                            <dt class="font-medium">@lang("google_merchant::app.logs.datagrid.$label")</dt>
                            <dd>{{ $log->{$field} ?? '—' }}</dd>
                        @endforeach
                    </dl>

                    @if ($log->error_message)
                        <p class="mt-3 text-sm text-gray-700 dark:text-gray-300">{{ $log->error_message }}</p>
                    @endif
                </div>

                @if ($pretty)
                    <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                        <p class="mb-2 text-base font-semibold text-gray-800 dark:text-white">@lang('google_merchant::app.logs.response')</p>
                        <pre class="overflow-x-auto whitespace-pre-wrap break-all rounded bg-gray-50 p-3 text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-300">{{ $pretty }}</pre>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-admin::layouts>
