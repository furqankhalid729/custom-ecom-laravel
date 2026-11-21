<x-admin::layouts>
    <x-slot:title>
        @lang('google_merchant::app.diagnostics.title')
    </x-slot>

    @include('google_merchant::admin.partials.styles')

    @php
        $t = fn ($key, $replace = []) => trans("google_merchant::app.diagnostics.$key", $replace);
        $statusClass = ['approved' => 'gmc-ok', 'partial' => 'gmc-warn', 'disapproved' => 'gmc-bad', 'pending' => 'gmc-info', 'awaiting' => 'gmc-muted'];
        $severity = fn (string $s) => \Illuminate\Support\Facades\Lang::has("google_merchant::app.diagnostics.severity.$s") ? $t("severity.$s") : $s;
    @endphp

    <div v-pre>
        <div class="mb-4 flex items-center justify-between gap-4 max-sm:flex-wrap">
            <div>
                <p class="text-xl font-bold text-gray-800 dark:text-white">{{ $t('title') }}</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $t('intro') }}</p>
            </div>

            <div class="flex items-center gap-2 max-sm:flex-wrap">
                @include('google_merchant::admin.partials.market-switcher')

                @if ($live && bouncer()->hasPermission('marketing.google_merchant.diagnostics.refresh'))
                    <form method="POST" action="{{ route('admin.google_merchant.diagnostics.refresh') }}">
                        @csrf
                        <button type="submit" class="primary-button">{{ $t('refresh') }}</button>
                    </form>
                @endif
            </div>
        </div>

        @if (! $target)
            <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                <p class="text-sm text-gray-700 dark:text-gray-300">{{ $t('not-connected') }}</p>
                <a href="{{ route('admin.google_merchant.connection.index') }}" class="primary-button mt-3 inline-block">@lang('google_merchant::app.menu.connection')</a>
            </div>
        @else
            <div class="flex flex-col gap-2.5">
                {{-- Google review status --}}
                <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                    <p class="text-base font-semibold text-gray-800 dark:text-white">{{ $t('statuses') }}</p>
                    <p class="mb-3 mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $t('statuses-help') }}</p>

                    <div class="flex flex-wrap gap-3">
                        @foreach (['approved', 'partial', 'disapproved', 'pending', 'awaiting'] as $status)
                            <div class="gmc-stat rounded border border-gray-200 p-3 dark:border-gray-800">
                                <span class="block text-2xl font-bold text-gray-800 dark:text-white">{{ number_format($summary[$status]) }}</span>
                                <span class="gmc-badge {{ $statusClass[$status] }}">@lang("google_merchant::app.google-status.$status")</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Product problems --}}
                <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                    <p class="mb-3 text-base font-semibold text-gray-800 dark:text-white">{{ $t('product-issues') }}</p>

                    @if (! collect($groups)->flatten(1)->count())
                        <p class="text-sm text-gray-600 dark:text-gray-300">{{ $t('no-product-issues') }}</p>
                    @endif

                    @foreach ($groups as $group => $issues)
                        @continue(! $issues)

                        <p class="mb-2 mt-4 text-sm font-semibold uppercase text-gray-600 dark:text-gray-300">{{ $t("groups.$group") }}</p>

                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm text-gray-700 dark:text-gray-300">
                                <thead>
                                    <tr class="border-b dark:border-gray-800">
                                        <th class="py-2 pr-3">{{ $t('issue') }}</th>
                                        <th class="py-2 pr-3">{{ $t('products') }}</th>
                                        <th class="py-2 pr-3">{{ $t('how-to-fix') }}</th>
                                        <th class="py-2"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($issues as $issue)
                                        <tr class="border-b align-top dark:border-gray-800">
                                            <td class="py-2 pr-3 font-medium">{{ $issue['description'] }}</td>
                                            <td class="py-2 pr-3">{{ number_format($issue['count']) }}</td>
                                            <td class="py-2 pr-3">
                                                {{ $issue['hint'] }}
                                                @if ($issue['documentation'])
                                                    <a href="{{ $issue['documentation'] }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 underline">{{ $t('learn-more') }}</a>
                                                @endif
                                            </td>
                                            <td class="whitespace-nowrap py-2">
                                                <a href="{{ $issue['link'] }}" class="text-blue-600 underline">{{ $t('view-products') }}</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endforeach
                </div>

                <div class="flex gap-2.5 max-xl:flex-wrap">
                    {{-- Account problems --}}
                    <div class="box-shadow flex-1 rounded bg-white p-4 dark:bg-gray-900">
                        <p class="mb-3 text-base font-semibold text-gray-800 dark:text-white">{{ $t('account-issues') }}</p>

                        @if ($live && $accountIssues === null)
                            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $t('google-unavailable') }}</p>
                        @elseif (! $accountIssues)
                            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $t('no-account-issues') }}</p>
                        @else
                            <ul class="flex flex-col gap-3">
                                @foreach ($accountIssues as $issue)
                                    <li class="text-sm text-gray-700 dark:text-gray-300">
                                        <span class="gmc-badge {{ $issue['severity'] === 'SUGGESTION' ? 'gmc-info' : 'gmc-bad' }}">{{ $severity($issue['severity']) }}</span>
                                        <span class="font-semibold">{{ $issue['title'] }}</span>

                                        @if ($issue['detail'])
                                            <p class="mt-1">{{ $issue['detail'] }}</p>
                                        @endif

                                        @if ($issue['countries'])
                                            <p class="mt-1 text-xs text-gray-500">{{ $t('countries') }}: {{ implode(', ', $issue['countries']) }}</p>
                                        @endif

                                        @if ($issue['documentation'])
                                            <a href="{{ $issue['documentation'] }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 underline">{{ $t('learn-more') }}</a>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    {{-- Quota --}}
                    <div class="box-shadow flex-1 rounded bg-white p-4 dark:bg-gray-900">
                        <p class="text-base font-semibold text-gray-800 dark:text-white">{{ $t('quota') }}</p>
                        <p class="mb-3 mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $t('quota-help') }}</p>

                        @if ($live && $quotas === null)
                            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $t('google-unavailable') }}</p>
                        @elseif (! $quotas)
                            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $t('no-quota') }}</p>
                        @else
                            <table class="w-full text-left text-sm text-gray-700 dark:text-gray-300">
                                <thead>
                                    <tr class="border-b dark:border-gray-800">
                                        <th class="py-2 pr-3">{{ $t('quota-method') }}</th>
                                        <th class="py-2 pr-3">{{ $t('quota-used') }}</th>
                                        <th class="py-2">{{ $t('quota-limit') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($quotas as $quota)
                                        <tr class="border-b dark:border-gray-800">
                                            <td class="py-2 pr-3">{{ $quota['name'] }}</td>
                                            <td class="py-2 pr-3 {{ $quota['percent'] >= 90 ? 'font-semibold text-red-600' : '' }}">{{ number_format($quota['usage']) }} ({{ $quota['percent'] }}%)</td>
                                            <td class="py-2">{{ number_format($quota['limit']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-admin::layouts>
