<x-admin::layouts>
    <x-slot:title>
        @lang('google_merchant::app.overview.title')
    </x-slot>

    @include('google_merchant::admin.partials.styles')

    @php
        $t = fn ($key, $replace = []) => trans("google_merchant::app.overview.$key", $replace);
        $products = route('admin.google_merchant.products.index');
    @endphp

    <div v-pre>
        <div class="mb-4 flex items-center justify-between gap-4 max-sm:flex-wrap">
            <div>
                <p class="text-xl font-bold text-gray-800 dark:text-white">{{ $t('title') }}</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $t('intro') }}</p>
            </div>

            <div class="flex gap-2">
                <a href="{{ $products }}" class="secondary-button">{{ $t('view-products') }}</a>
                <a href="{{ route('admin.google_merchant.connection.index') }}" class="secondary-button">{{ $t('connection') }}</a>
            </div>
        </div>

        @include('google_merchant::admin.partials.status-banner', ['account' => $account, 'checks' => $checks])

        @if (! $account || $account->status === 'disconnected')
            <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                <p class="text-base font-semibold text-gray-800 dark:text-white">{{ $t('not-connected') }}</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $t('not-connected-help') }}</p>
                <a href="{{ route('admin.google_merchant.connection.index') }}" class="primary-button mt-3 inline-block">{{ $t('connect') }}</a>
            </div>
        @else
            <div class="flex gap-2.5 max-xl:flex-wrap">
                <div class="flex flex-1 flex-col gap-2 max-xl:flex-auto">
                    <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                        <dl class="grid grid-cols-2 gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <dt class="font-medium">{{ $t('merchant-id') }}</dt>
                            <dd>{{ $account->merchant_id ?? '—' }} {{ $account->display_name ? '('.$account->display_name.')' : '' }}</dd>
                            <dt class="font-medium">{{ $t('google-account') }}</dt>
                            <dd>{{ $account->google_email ?? '—' }}</dd>
                            <dt class="font-medium">{{ $t('market') }}</dt>
                            <dd>{{ $target ? implode(', ', $target->countries).' / '.$target->content_language.' / '.$target->currency : '—' }}</dd>
                            <dt class="font-medium">{{ $t('last-success') }}</dt>
                            <dd>{{ $stats['last_success_at'] ?? null ? core()->formatDate($stats['last_success_at'], 'd M Y H:i') : '—' }}</dd>
                        </dl>
                    </div>

                    @if ($stats)
                        <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                            <p class="mb-3 text-base font-semibold text-gray-800 dark:text-white">{{ $t('sync-status') }}</p>

                            <div class="flex flex-wrap gap-3">
                                @foreach ([
                                    'synced' => 'gmc-ok', 'pending' => 'gmc-info', 'failed' => 'gmc-bad',
                                    'invalid' => 'gmc-warn', 'excluded' => 'gmc-muted',
                                ] as $key => $class)
                                    <a href="{{ $products }}" class="gmc-stat rounded border border-gray-200 p-3 dark:border-gray-800">
                                        <span class="block text-2xl font-bold text-gray-800 dark:text-white">{{ $stats[$key] }}</span>
                                        <span class="gmc-badge {{ $class }}">{{ $t($key) }}</span>
                                    </a>
                                @endforeach
                            </div>

                            <p class="mb-3 mt-5 text-base font-semibold text-gray-800 dark:text-white">{{ $t('google-review') }}</p>

                            <div class="flex flex-wrap gap-3">
                                @foreach (['approved' => 'gmc-ok', 'google_pending' => 'gmc-info', 'partial' => 'gmc-warn', 'disapproved' => 'gmc-bad'] as $key => $class)
                                    <div class="gmc-stat rounded border border-gray-200 p-3 dark:border-gray-800">
                                        <span class="block text-2xl font-bold text-gray-800 dark:text-white">{{ $stats[$key] }}</span>
                                        <span class="gmc-badge {{ $class }}">{{ $t($key) }}</span>
                                    </div>
                                @endforeach
                            </div>

                            <p class="mt-3 text-xs text-gray-600 dark:text-gray-400">{{ $t('submitted-note') }}</p>
                        </div>
                    @endif
                </div>

                <div class="gmc-side flex flex-col gap-2">
                    @include('google_merchant::admin.partials.checklist', ['checks' => $checks])
                </div>
            </div>
        @endif
    </div>
</x-admin::layouts>
