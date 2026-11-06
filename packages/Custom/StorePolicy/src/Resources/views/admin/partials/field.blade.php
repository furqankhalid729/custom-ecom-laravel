{{--
    One settings field.
    $path: dotted settings path; $label; $type: text|email|number|date|time|textarea|list|checkbox|select|multiselect;
    $required (bool); $options (value => label, for select/multiselect); $hint.
--}}
@php
    $type ??= 'text';
    $required ??= false;
    $hint ??= null;
    $segments = explode('.', $path);
    $name = array_shift($segments).implode('', array_map(fn ($s) => "[{$s}]", $segments));
    $id = 'sp-'.str_replace('.', '-', $path);
    $value = old($path, data_get($settings, $path));
    $error = $errors->first($path) ?: $errors->first($path.'.*');
    $inputClass = 'w-full rounded-md border px-3 py-2.5 text-sm text-gray-600 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400'.($error ? ' !border-red-600' : '');
@endphp

<div class="mb-4">
    @if ($type === 'checkbox')
        <input type="hidden" name="{{ $name }}" value="0">

        <label for="{{ $id }}" class="flex cursor-pointer items-center gap-2 text-sm text-gray-800 dark:text-white">
            <input type="checkbox" id="{{ $id }}" name="{{ $name }}" value="1" @checked(filter_var($value, FILTER_VALIDATE_BOOLEAN))>

            {{ $label }}
        </label>
    @else
        <label for="{{ $id }}" class="mb-1.5 flex items-center gap-1 text-xs font-medium text-gray-800 dark:text-white {{ $required ? 'required' : '' }}">
            {{ $label }}
        </label>

        @switch($type)
            @case('textarea')
            @case('list')
                <textarea
                    id="{{ $id }}"
                    name="{{ $name }}"
                    rows="{{ $type === 'list' ? 4 : 2 }}"
                    class="{{ $inputClass }}"
                >{{ is_array($value) ? implode("\n", $value) : $value }}</textarea>
                @break

            @case('select')
                <select id="{{ $id }}" name="{{ $name }}" class="{{ $inputClass }}">
                    @foreach ($options as $optionValue => $optionLabel)
                        <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>
                    @endforeach
                </select>
                @break

            @case('multiselect')
                <select id="{{ $id }}" name="{{ $name }}[]" multiple size="8" class="{{ $inputClass }}">
                    @foreach ($options as $optionValue => $optionLabel)
                        <option value="{{ $optionValue }}" @selected(in_array($optionValue, (array) $value, true))>{{ $optionLabel }}</option>
                    @endforeach
                </select>
                @break

            @default
                <input
                    type="{{ $type }}"
                    id="{{ $id }}"
                    name="{{ $name }}"
                    value="{{ is_array($value) ? '' : $value }}"
                    @if ($type === 'number') min="0" step="1" @endif
                    class="{{ $inputClass }}"
                >
        @endswitch
    @endif

    @if ($hint)
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
    @endif

    @if ($error)
        <p class="mt-1 text-xs italic text-red-600">{{ $error }}</p>
    @endif
</div>
