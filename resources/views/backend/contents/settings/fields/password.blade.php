{{--
    password field partial — provider API keys.
    Variables: $group (string), $key (string), $field (array), $value (mixed, unused — never rendered)

    Expects the controller to inject $field['is_set'] (bool) + $field['masked']
    (string|null, e.g. "••••1234"). The secret itself is NEVER passed into value=.
--}}
<div class="row mb-6">
    <label class="col-lg-4 col-form-label fw-bold fs-6" for="setting_{{ $group }}_{{ $key }}">
        {{ $field['label'] ?? $key }}
    </label>
    <div class="col-lg-8 fv-row">
        <div class="d-flex align-items-center gap-3 mb-2">
            @if (! empty($field['is_set']))
                <span class="badge badge-light-success fs-8">
                    défini {{ $field['masked'] ?? '' }}
                </span>
            @else
                <span class="badge badge-light-warning fs-8">non défini</span>
            @endif
        </div>
        <input
            type="password"
            name="settings[{{ $group }}][{{ $key }}]"
            id="setting_{{ $group }}_{{ $key }}"
            class="form-control form-control-lg form-control-solid @error("settings.{$group}.{$key}") is-invalid @enderror"
            placeholder="Laisser vide pour conserver la clé actuelle"
            value=""
            autocomplete="new-password"
        >
        <div class="form-check form-check-sm mt-2">
            <input
                type="checkbox"
                class="form-check-input"
                name="reset[{{ $group }}][{{ $key }}]"
                id="reset_{{ $group }}_{{ $key }}"
                value="1"
            >
            <label class="form-check-label text-muted" for="reset_{{ $group }}_{{ $key }}">
                Réinitialiser (utiliser .env)
            </label>
        </div>
        <div class="text-muted fs-7 mt-1">
            Efface la clé enregistrée et revient à la valeur définie sur le serveur.
        </div>
        @if (! empty($field['help']))
            <div class="text-muted fs-7 mt-2">{{ $field['help'] }}</div>
        @endif
        @error("settings.{$group}.{$key}")
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </div>
</div>
