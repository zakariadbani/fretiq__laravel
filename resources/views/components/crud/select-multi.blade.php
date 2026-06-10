{{--
    Reusable multi-select component with mandatory orphan-option guard.

    Usage (see form.blade.php for live examples):
        Flat list (tags):   name="sectors" :options="$sectorsList" :tags="true"
        ISO key=>label map: name="countries" :options="$countries" :labelSuffix="true"
        Grouped optgroups:  name="target_positions" :options="$positionGroups" :tags="true"

    Props:
        name        (string)       — field name; rendered as {name}[]
        options     (array)        — flat list (indexed), key=>label map, or group=>[items] nested
        selected    (array)        — currently selected values
        tags        (bool)         — enable free-text entry via select2 data-tags
        placeholder (string)       — select2 placeholder text
        hint        (string|null)  — helper text rendered below the select
        labelSuffix (bool)         — when true, renders "Label (KEY)" for key=>label maps (countries)

    Orphan-option guard:
        Every $selected value not present in $options is rendered as an <option selected>
        BEFORE the main option list. This preserves legacy free-text values on edit.
        Generalised from resources/views/backend/contents/companies/crud/form.blade.php.
--}}
@props([
    'name',
    'options'     => [],
    'selected'    => [],
    'tags'        => false,
    'placeholder' => '',
    'hint'        => null,
    'labelSuffix' => false,
])

@php
    $selected = is_array($selected) ? $selected : [];

    // Determine if $options is grouped (nested array of arrays) or flat.
    // A nested map has array values; a flat indexed list has string values.
    $isGrouped = false;
    $isKeyValue = false;
    if (!empty($options)) {
        $firstValue = reset($options);
        if (is_array($firstValue)) {
            $isGrouped = true;
        } elseif (!array_is_list($options)) {
            // key => label map (e.g. company_countries)
            $isKeyValue = true;
        }
        // else: flat indexed list of strings
    }

    // Build a flat set of all option values for orphan detection.
    $allOptionValues = [];
    if ($isGrouped) {
        foreach ($options as $groupItems) {
            foreach ($groupItems as $item) {
                $allOptionValues[] = $item;
            }
        }
    } elseif ($isKeyValue) {
        $allOptionValues = array_keys($options);
    } else {
        $allOptionValues = array_values($options);
    }

    // Orphan values: selected values not present in the known options list.
    $orphans = array_values(array_filter($selected, fn($v) => !in_array($v, $allOptionValues, true)));
@endphp

<select
    name="{{ $name }}[]"
    multiple
    class="form-select form-select-solid"
    data-control="select2"
    data-placeholder="{{ $placeholder }}"
    @if($tags) data-tags="true" @endif
>
    {{-- Orphan-option guard: render legacy/free-text values that are not in the options list --}}
    @foreach($orphans as $orphan)
        <option value="{{ $orphan }}" selected>{{ $orphan }}</option>
    @endforeach

    @if($isGrouped)
        {{-- Grouped options (optgroups) --}}
        @foreach($options as $group => $items)
            <optgroup label="{{ $group }}">
                @foreach($items as $item)
                    <option value="{{ $item }}"
                        {{ in_array($item, $selected, true) ? 'selected' : '' }}>
                        {{ $item }}
                    </option>
                @endforeach
            </optgroup>
        @endforeach

    @elseif($isKeyValue)
        {{-- Key => label map (e.g. ISO codes → country names) --}}
        @foreach($options as $key => $label)
            <option value="{{ $key }}"
                {{ in_array($key, $selected, true) ? 'selected' : '' }}>
                @if($labelSuffix)
                    {{ $label }} ({{ $key }})
                @else
                    {{ $label }}
                @endif
            </option>
        @endforeach

    @else
        {{-- Flat indexed list of strings --}}
        @foreach($options as $item)
            <option value="{{ $item }}"
                {{ in_array($item, $selected, true) ? 'selected' : '' }}>
                {{ $item }}
            </option>
        @endforeach
    @endif
</select>

@if($hint)
    <div class="form-text text-muted mt-1">{{ $hint }}</div>
@endif
