{{--
    text field partial.
    Variables: $group (string), $key (string), $field (array), $value (mixed)
--}}
<div class="row mb-6">
    <label class="col-lg-4 col-form-label fw-bold fs-6" for="setting_{{ $group }}_{{ $key }}">
        {{ $field['label'] ?? $key }}
    </label>
    <div class="col-lg-8 fv-row">
        <input
            type="text"
            name="settings[{{ $group }}][{{ $key }}]"
            id="setting_{{ $group }}_{{ $key }}"
            class="form-control form-control-lg form-control-solid @error("settings.{$group}.{$key}") is-invalid @enderror"
            placeholder="{{ $field['placeholder'] ?? '' }}"
            value="{{ old("settings.{$group}.{$key}", $value ?? ($field['default'] ?? '')) }}"
        >
        @if (! empty($field['help']))
            <div class="text-muted fs-7 mt-2">{{ $field['help'] }}</div>
        @endif
        @error("settings.{$group}.{$key}")
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </div>
</div>
