<x-admin::layouts>
    <x-slot:title>
        @lang('review_import::app.index.title')
    </x-slot>

    {{-- Plain HTML form: v-pre keeps Vue from compiling the page. --}}
    <div v-pre>
        <div class="mb-4">
            <p class="text-xl font-bold text-gray-800 dark:text-white">@lang('review_import::app.index.title')</p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">@lang('review_import::app.index.intro')</p>
        </div>

        <div class="flex gap-2.5 max-xl:flex-wrap">
            <div class="flex flex-1 flex-col gap-2 max-xl:flex-auto">
                <!-- Step 1: template -->
                <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                    <p class="mb-2 text-base font-semibold text-gray-800 dark:text-white">@lang('review_import::app.index.step-1')</p>
                    <p class="mb-4 text-sm text-gray-600 dark:text-gray-300">@lang('review_import::app.index.step-1-info')</p>

                    <a href="{{ route('admin.customers.reviews.import.template') }}" class="secondary-button inline-block">
                        @lang('review_import::app.index.download-template')
                    </a>
                </div>

                <!-- Step 2: upload -->
                <form
                    method="POST"
                    action="{{ route('admin.customers.reviews.import.preview') }}"
                    enctype="multipart/form-data"
                    class="box-shadow rounded bg-white p-4 dark:bg-gray-900"
                >
                    @csrf

                    <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">@lang('review_import::app.index.step-2')</p>

                    <label for="review-import-file" class="mb-1.5 block text-xs font-medium text-gray-800 dark:text-white">
                        @lang('review_import::app.index.file')
                    </label>

                    <input
                        type="file"
                        id="review-import-file"
                        name="file"
                        accept=".csv,text/csv"
                        required
                        class="w-full rounded-md border px-3 py-2.5 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                    >

                    @error('file')
                        <p class="mt-1 text-xs italic text-red-600">{{ $message }}</p>
                    @enderror

                    <button type="submit" class="primary-button mt-4">
                        @lang('review_import::app.index.preview-btn')
                    </button>
                </form>
            </div>

            <!-- Column reference -->
            <div class="flex w-[460px] max-w-full flex-col gap-2 max-xl:w-full">
                <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                    <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">@lang('review_import::app.index.columns-title')</p>

                    <div class="grid gap-3">
                        @foreach (\Custom\ReviewImport\Services\ReviewCsvParser::COLUMNS as $column)
                            <div>
                                <p class="text-sm font-semibold text-gray-800 dark:text-white">
                                    <code>{{ $column }}</code>

                                    <span class="ml-1 text-xs font-normal {{ in_array($column, \Custom\ReviewImport\Services\ReviewCsvParser::REQUIRED_COLUMNS) ? 'text-red-600' : 'text-gray-500' }}">
                                        {{ in_array($column, \Custom\ReviewImport\Services\ReviewCsvParser::REQUIRED_COLUMNS) ? trans('review_import::app.index.required') : trans('review_import::app.index.optional') }}
                                    </span>
                                </p>

                                <p class="text-sm text-gray-600 dark:text-gray-300">@lang("review_import::app.index.columns.{$column}")</p>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                    <p class="mb-2 text-base font-semibold text-gray-800 dark:text-white">@lang('review_import::app.index.notes-title')</p>

                    <ul class="list-disc pl-5 text-sm text-gray-600 dark:text-gray-300">
                        @foreach (trans('review_import::app.index.notes') as $note)
                            <li class="mb-1">{{ $note }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>
</x-admin::layouts>
