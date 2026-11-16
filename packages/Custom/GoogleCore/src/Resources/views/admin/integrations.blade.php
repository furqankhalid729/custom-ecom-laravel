<x-admin::layouts>
    <x-slot:title>
        @lang('google_core::app.integrations.title')
    </x-slot>

    @php($t = fn ($key) => trans("google_core::app.integrations.$key"))

    <div v-pre>
        <div class="mb-4">
            <p class="text-xl font-bold text-gray-800 dark:text-white">{{ $t('title') }}</p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $t('intro') }}</p>
        </div>

        @if ($integrations->isEmpty())
            <div class="box-shadow rounded bg-white p-4 text-sm text-gray-600 dark:bg-gray-900 dark:text-gray-300">{{ $t('empty') }}</div>
        @else
            <div class="grid grid-cols-3 gap-2.5 max-xl:grid-cols-2 max-sm:grid-cols-1">
                @foreach ($integrations as $integration)
                    <div class="box-shadow flex flex-col justify-between gap-3 rounded bg-white p-4 dark:bg-gray-900">
                        <div>
                            <p class="text-base font-semibold text-gray-800 dark:text-white">{{ $integration['label'] }}</p>
                            <p class="mt-1 text-sm {{ $integration['connected'] ? 'text-green-700 dark:text-green-400' : 'text-gray-600 dark:text-gray-400' }}">
                                {{ $integration['connected'] ? $t('connected') : $t('not-connected') }}
                            </p>
                        </div>

                        @if ($integration['url'])
                            <a href="{{ $integration['url'] }}" class="secondary-button self-start">{{ $t('open') }}</a>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-admin::layouts>
