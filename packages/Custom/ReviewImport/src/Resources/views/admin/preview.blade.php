@php
    $rows = $result['rows'];
    $rowErrors = $result['errors'];
    $shown = array_slice($rows, 0, 100, true);
    $t = fn ($key, $replace = []) => trans("review_import::app.preview.{$key}", $replace);
@endphp

<x-admin::layouts>
    <x-slot:title>
        {{ $t('title') }}
    </x-slot>

    {{-- v-pre: review text comes from the uploaded file, so Vue must not compile it. --}}
    <div v-pre>
        <div class="mb-4 flex items-center justify-between gap-4 max-sm:flex-wrap">
            <div>
                <p class="text-xl font-bold text-gray-800 dark:text-white">{{ $t('title') }}</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $t('file', ['name' => $filename]) }}</p>
            </div>

            <div class="flex items-center gap-2.5">
                <a href="{{ route('admin.customers.reviews.import.index') }}" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">
                    {{ $t('back-btn') }}
                </a>

                @if ($token)
                    <form method="POST" action="{{ route('admin.customers.reviews.import.store') }}">
                        @csrf

                        <input type="hidden" name="token" value="{{ $token }}">

                        <button type="submit" class="primary-button">
                            {{ $t('confirm-btn', ['count' => count($rows)]) }}
                        </button>
                    </form>
                @endif
            </div>
        </div>

        <!-- Summary -->
        <div class="mb-2.5 grid grid-cols-3 gap-2.5 max-md:grid-cols-1">
            <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ $t('total') }}</p>
                <p class="text-2xl font-bold text-gray-800 dark:text-white">{{ $result['total'] }}</p>
            </div>

            <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ $t('valid') }}</p>
                <p class="text-2xl font-bold text-green-600">{{ count($rows) }}</p>
            </div>

            <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ $t('invalid') }}</p>
                <p class="text-2xl font-bold {{ $rowErrors ? 'text-red-600' : 'text-gray-800 dark:text-white' }}">{{ isset($rowErrors[0]) ? '-' : count($rowErrors) }}</p>
            </div>
        </div>

        @unless ($token)
            <p class="mb-2.5 rounded bg-red-50 px-3 py-2 text-sm text-red-800 dark:bg-gray-800 dark:text-red-300">{{ $t('nothing') }}</p>
        @endunless

        <!-- Errors -->
        @if ($rowErrors)
            <div class="box-shadow mb-2.5 rounded bg-white p-4 dark:bg-gray-900">
                <p class="mb-3 text-base font-semibold text-gray-800 dark:text-white">{{ $t('errors-title') }}</p>

                <div class="grid gap-1.5">
                    @foreach ($rowErrors as $line => $messages)
                        <p class="text-sm text-gray-600 dark:text-gray-300">
                            <span class="font-semibold text-red-600">{{ $line === 0 ? $t('file-error') : $t('line', ['line' => $line]) }}:</span>
                            {{ implode(' ', $messages) }}
                        </p>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- Valid rows -->
        @if ($rows)
            <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                <p class="mb-1 text-base font-semibold text-gray-800 dark:text-white">{{ $t('rows-title') }}</p>

                @if (count($rows) > count($shown))
                    <p class="mb-3 text-sm text-gray-600 dark:text-gray-300">{{ $t('showing', ['shown' => count($shown), 'total' => count($rows)]) }}</p>
                @endif

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-gray-600 dark:text-gray-300">
                        <thead class="border-b text-gray-800 dark:border-gray-800 dark:text-white">
                            <tr>
                                @foreach (['line', 'sku', 'name', 'rating', 'title', 'comment', 'status', 'date'] as $column)
                                    <th class="px-2 py-2 font-semibold">{{ $t("columns.{$column}") }}</th>
                                @endforeach
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($shown as $line => $row)
                                <tr class="border-b align-top dark:border-gray-800">
                                    <td class="px-2 py-2">{{ $line }}</td>
                                    <td class="px-2 py-2">{{ $row['sku'] }}</td>
                                    <td class="px-2 py-2">{{ $row['name'] }}</td>
                                    <td class="px-2 py-2 whitespace-nowrap">{{ str_repeat('★', $row['rating']) }}{{ str_repeat('☆', 5 - $row['rating']) }}</td>
                                    <td class="px-2 py-2">{{ $row['title'] }}</td>
                                    <td class="px-2 py-2">{{ \Illuminate\Support\Str::limit($row['comment'] ?? '', 120) }}</td>
                                    <td class="px-2 py-2">{{ $row['status'] }}</td>
                                    <td class="px-2 py-2 whitespace-nowrap">{{ $row['created_at'] ? substr($row['created_at'], 0, 10) : '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</x-admin::layouts>
