{{-- Variables: $group, $key, $field, $value --}}
@php
    $selected = old("settings.{$group}.{$key}", $value ?? ($field['default'] ?? []));
    $selected = is_array($selected) ? $selected : [];
@endphp
<div class="row mb-6">
    <label class="col-lg-4 col-form-label fw-bold fs-6" for="setting_{{ $group }}_{{ $key }}">
        {{ $field['label'] ?? $key }}
    </label>
    <div class="col-lg-8 fv-row">
        <select
            multiple
            name="settings[{{ $group }}][{{ $key }}][]"
            id="setting_{{ $group }}_{{ $key }}"
            class="form-select form-select-lg form-select-solid @error("settings.{$group}.{$key}") is-invalid @enderror"
            data-control="select2"
            data-placeholder="Sélectionnez au moins un moteur"
        >
            @foreach ($field['options'] ?? [] as $optionValue => $optionLabel)
                <option value="{{ $optionValue }}" @selected(in_array($optionValue, $selected, true))>{{ $optionLabel }}</option>
            @endforeach
        </select>
        @if (! empty($field['help']))
            <div class="text-muted fs-7 mt-2">{{ $field['help'] }}</div>
        @endif
        @error("settings.{$group}.{$key}")
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
        @error("settings.{$group}.{$key}.*")
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </div>
</div>
