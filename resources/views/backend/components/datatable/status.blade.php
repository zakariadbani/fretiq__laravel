{{-- Toggle switch for boolean fields in DataTables --}}
<div class="form-check form-switch form-check-custom form-check-solid">
    <label class="visually-hidden" for="status_{{ $name }}_{{ $model->id }}">Modifier : {{ $toggleLabel ?? 'Statut actif' }}</label>
    <input class="form-check-input status-toggle"
           type="checkbox"
           data-id="{{ $model->id }}"
           data-field="{{ $name }}"
           data-route="{{ route('admin.' . $model->getTable() . '.executeSwitch', ['id' => $model->id]) }}"
           {{ data_get($model, $name) ? 'checked' : '' }}
           role="switch"
           aria-label="Modifier : {{ $toggleLabel ?? 'Statut actif' }}"
           id="status_{{ $name }}_{{ $model->id }}" />
</div>
