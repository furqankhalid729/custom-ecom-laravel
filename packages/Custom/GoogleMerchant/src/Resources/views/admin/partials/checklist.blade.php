{{-- $checks: array<string, array{ok: bool, message: string}> --}}
<div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
    <p class="mb-3 text-base font-semibold text-gray-800 dark:text-white">@lang('google_merchant::app.connection.checklist-title')</p>

    <ul class="flex flex-col gap-2">
        @foreach ($checks as $key => $check)
            <li class="flex items-start gap-2 text-sm">
                <span class="gmc-badge {{ $check['ok'] ? 'gmc-ok' : 'gmc-warn' }}">{{ $check['ok'] ? '✓' : '!' }}</span>

                <span class="text-gray-700 dark:text-gray-300">
                    <span class="font-medium">@lang("google_merchant::app.checklist.$key")</span>

                    @unless ($check['ok'])
                        <span class="block text-gray-600 dark:text-gray-400">{{ $check['message'] }}</span>
                    @endunless
                </span>
            </li>
        @endforeach
    </ul>
</div>
