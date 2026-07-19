{{--
    textarea field partial.
    Variables: $group (string), $key (string), $field (array), $value (mixed)

    Optional $field keys: rows (default 8), placeholder, help.
--}}
<div class="row mb-6">
    <label class="col-lg-4 col-form-label fw-bold fs-6" for="setting_{{ $group }}_{{ $key }}">
        {{ $field['label'] ?? $key }}
    </label>
    <div class="col-lg-8 fv-row">
        <textarea
            name="settings[{{ $group }}][{{ $key }}]"
            id="setting_{{ $group }}_{{ $key }}"
            rows="{{ $field['rows'] ?? 8 }}"
            class="form-control form-control-lg form-control-solid font-monospace fs-7 @error("settings.{$group}.{$key}") is-invalid @enderror"
            placeholder="{{ $field['placeholder'] ?? '' }}"
        >{{ old("settings.{$group}.{$key}", $value ?? ($field['default'] ?? '')) }}</textarea>
        @if (! empty($field['help']))
            <div class="text-muted fs-7 mt-2">{{ $field['help'] }}</div>
        @endif
        @error("settings.{$group}.{$key}")
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </div>
</div>
