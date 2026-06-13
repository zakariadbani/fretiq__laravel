{{--
    static field partial — read-only display value, no input rendered.
    Variables: $group (string), $key (string), $field (array), $value (mixed)

    Expects the controller to inject $field['static_value'] and optionally
    $field['badge_text'] + $field['badge_class'].
--}}
<div class="row mb-6">
    <label class="col-lg-4 col-form-label fw-bold fs-6">
        {{ $field['label'] ?? $key }}
    </label>
    <div class="col-lg-8 fv-row d-flex align-items-center gap-3">
        <span class="fw-semibold text-gray-700">{{ $field['static_value'] ?? '—' }}</span>
        @if (! empty($field['badge_text']))
            <span class="badge {{ $field['badge_class'] ?? 'badge-light-secondary' }} fs-8">
                {{ $field['badge_text'] }}
            </span>
        @endif
    </div>
</div>
