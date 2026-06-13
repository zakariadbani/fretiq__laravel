{{--
    boolean field partial.
    Variables: $group (string), $key (string), $field (array), $value (mixed)

    Uses a hidden=0 input before the checkbox so unchecked posts 0 (still handled
    server-side as false, but provides a consistent POST key).
--}}
<div class="row mb-6">
    <label class="col-lg-4 col-form-label fw-bold fs-6" for="setting_{{ $group }}_{{ $key }}">
        {{ $field['label'] ?? $key }}
    </label>
    <div class="col-lg-8 fv-row">
        <div class="form-check form-switch form-check-custom form-check-solid">
            {{-- Hidden fallback: ensures the key is present in POST even when unchecked --}}
            <input type="hidden" name="settings[{{ $group }}][{{ $key }}]" value="0">
            <input
                class="form-check-input @error("settings.{$group}.{$key}") is-invalid @enderror"
                type="checkbox"
                name="settings[{{ $group }}][{{ $key }}]"
                value="1"
                id="setting_{{ $group }}_{{ $key }}"
                @checked((bool) old("settings.{$group}.{$key}", $value ?? ($field['default'] ?? false)))
            >
            <label class="form-check-label" for="setting_{{ $group }}_{{ $key }}">
            </label>
        </div>
        @if (! empty($field['help']))
            <div class="text-muted fs-7 mt-2">{{ $field['help'] }}</div>
        @endif
        @error("settings.{$group}.{$key}")
            <div class="text-danger fs-7 mt-1">{{ $message }}</div>
        @enderror
    </div>
</div>
