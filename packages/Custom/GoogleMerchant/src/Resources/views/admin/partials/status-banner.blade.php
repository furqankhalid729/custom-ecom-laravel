{{-- $account: ?GmcAccount, $checks: SetupChecklist::run() --}}
@php
    $failing = collect($checks)->reject(fn ($c) => $c['ok'])->keys();
@endphp

@if ($account?->status === 'needs_reauth')
    <div class="mb-4 rounded bg-red-50 px-4 py-3 text-sm text-red-700 dark:bg-gray-800">
        <span class="font-semibold">@lang('google_merchant::app.banner.reauth')</span>
        <a href="{{ route('admin.google_merchant.connection.index') }}" class="ml-2 underline">@lang('google_merchant::app.banner.reconnect')</a>
    </div>
@elseif ($account?->isConnected() && $failing->contains('queue_runner'))
    <div class="mb-4 rounded bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-gray-800">
        @lang('google_merchant::app.banner.worker')
    </div>
@elseif ($account?->isConnected() && $failing->isNotEmpty())
    <div class="mb-4 rounded bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-gray-800">
        @lang('google_merchant::app.banner.setup', ['count' => $failing->count()])
        <a href="{{ route('admin.google_merchant.connection.index') }}" class="ml-2 underline">@lang('google_merchant::app.banner.open-checklist')</a>
    </div>
@endif
