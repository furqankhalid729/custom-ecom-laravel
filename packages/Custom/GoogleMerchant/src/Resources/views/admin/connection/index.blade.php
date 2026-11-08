<x-admin::layouts>
    <x-slot:title>
        @lang('google_merchant::app.connection.title')
    </x-slot>

    @include('google_merchant::admin.partials.styles')

    @php
        $t = fn ($key, $replace = []) => trans("google_merchant::app.connection.$key", $replace);
        $status = $account?->status ?? 'disconnected';
        $statusClass = ['connected' => 'gmc-ok', 'needs_reauth' => 'gmc-bad'][$status] ?? 'gmc-muted';
    @endphp

    {{-- Plain HTML forms: v-pre keeps Vue from compiling text that came from Google or the admin. --}}
    <div v-pre>
        <div class="mb-4">
            <p class="text-xl font-bold text-gray-800 dark:text-white">{{ $t('title') }}</p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $t('intro') }}</p>
        </div>

        <div class="flex gap-2.5 max-xl:flex-wrap">
            <div class="flex flex-1 flex-col gap-2 max-xl:flex-auto">
                {{-- Account card --}}
                <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                    <div class="flex items-center justify-between gap-4">
                        <p class="text-base font-semibold text-gray-800 dark:text-white">{{ $t('status') }}</p>
                        <span class="gmc-badge {{ $statusClass }}">{{ $t('status-'.$status) }}</span>
                    </div>

                    @if ($account && $status !== 'disconnected')
                        <dl class="mt-3 grid grid-cols-2 gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <dt class="font-medium">{{ $t('google-account') }}</dt>
                            <dd>{{ $account->google_email ?? '—' }}</dd>
                            <dt class="font-medium">{{ $t('merchant-id') }}</dt>
                            <dd>{{ $account->merchant_id ?? '—' }} {{ $account->display_name ? '('.$account->display_name.')' : '' }}</dd>
                            <dt class="font-medium">{{ $t('market') }}</dt>
                            <dd>{{ $target ? implode(', ', $target->countries).' / '.$target->content_language.' / '.$target->currency : '—' }}</dd>
                            <dt class="font-medium">{{ $t('data-source') }}</dt>
                            <dd class="break-all">{{ $target?->data_source_name ?? '—' }}</dd>
                        </dl>

                        @if ($account->last_error && $status === 'needs_reauth')
                            <p class="mt-3 rounded bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-gray-800">{{ $account->last_error }}</p>
                        @endif
                    @endif
                </div>

                {{-- Choose merchant --}}
                @if ($account?->isConnected() && ! $account->merchant_id)
                    <form method="POST" action="{{ route('admin.google_merchant.merchant.select') }}" class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                        @csrf

                        <p class="mb-3 text-base font-semibold text-gray-800 dark:text-white">{{ $t('choose-merchant') }}</p>

                        @if ($merchantError)
                            <p class="mb-3 text-sm text-gray-600 dark:text-gray-300">{{ $t('merchant-list-error', ['reason' => $merchantError]) }}</p>
                        @endif

                        @if ($merchants)
                            <select name="merchant_id" class="mb-3 w-full rounded-md border px-3 py-2 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                                @foreach ($merchants as $merchant)
                                    <option value="{{ $merchant['accountId'] ?? '' }}">{{ $merchant['accountName'] ?? '' }} ({{ $merchant['accountId'] ?? '' }})</option>
                                @endforeach
                            </select>
                        @else
                            <label class="mb-1 block text-sm font-medium text-gray-800 dark:text-white">{{ $t('merchant-manual') }}</label>
                            <input name="merchant_id" inputmode="numeric" required class="mb-3 w-full rounded-md border px-3 py-2 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                        @endif

                        @if (! $account->developer_email)
                            <label class="mb-1 block text-sm font-medium text-gray-800 dark:text-white">{{ $t('developer-email') }}</label>
                            <input name="developer_email" type="email" class="mb-3 w-full rounded-md border px-3 py-2 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                        @endif

                        <button type="submit" class="primary-button">{{ $t('select-merchant') }}</button>
                    </form>
                @endif

                {{-- Connect --}}
                @if (! $account?->isConnected())
                    <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                        <p class="mb-3 text-base font-semibold text-gray-800 dark:text-white">{{ $t('auth-mode') }}</p>

                        <form method="POST" action="{{ route('admin.google_merchant.service_account') }}" enctype="multipart/form-data" class="mb-6">
                            @csrf

                            <p class="text-sm font-semibold text-gray-800 dark:text-white">{{ $t('service-account') }}</p>
                            <p class="mb-3 mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $t('service-account-help') }}</p>

                            <label class="mb-1 block text-sm font-medium text-gray-800 dark:text-white">{{ $t('key-file') }}</label>
                            <input type="file" name="key_file" accept="application/json,.json" required class="mb-1 block text-sm text-gray-600 dark:text-gray-300">
                            @error('key_file')
                                <p class="mb-2 text-xs text-red-600">{{ $message }}</p>
                            @enderror

                            <label class="mb-1 mt-3 block text-sm font-medium text-gray-800 dark:text-white">{{ $t('developer-email') }}</label>
                            <input type="email" name="developer_email" value="{{ old('developer_email', auth()->guard('admin')->user()?->email) }}" class="mb-3 w-full rounded-md border px-3 py-2 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">

                            <button type="submit" class="primary-button">{{ $t('connect-service-account') }}</button>
                        </form>

                        <form method="POST" action="{{ route('admin.google_merchant.oauth.start') }}">
                            @csrf

                            <p class="text-sm font-semibold text-gray-800 dark:text-white">{{ $t('oauth') }}</p>
                            <p class="mb-3 mt-1 break-all text-sm text-gray-600 dark:text-gray-300">{{ $t('oauth-help', ['uri' => $redirectUri]) }}</p>

                            @if ($oauthAvailable)
                                <button type="submit" class="secondary-button">{{ $status === 'needs_reauth' ? $t('reconnect-google') : $t('connect-google') }}</button>
                            @else
                                <p class="text-sm text-gray-600 dark:text-gray-300">{{ $t('missing-credentials') }}</p>
                            @endif
                        </form>
                    </div>
                @endif

                @if ($account && $status !== 'disconnected')
                    <form method="POST" action="{{ route('admin.google_merchant.disconnect') }}" onsubmit="return confirm(@js($t('disconnect-confirm')))" class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                        @csrf
                        @method('DELETE')

                        <button type="submit" class="secondary-button">{{ $t('disconnect') }}</button>
                    </form>
                @endif
            </div>

            <div class="gmc-side flex flex-col gap-2">
                @include('google_merchant::admin.partials.checklist', ['checks' => $checks])
            </div>
        </div>
    </div>
</x-admin::layouts>
