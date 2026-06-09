{{-- Toggle switch for boolean fields in DataTables --}}
<div class="form-check form-switch form-check-custom form-check-solid">
    <input class="form-check-input status-toggle"
           type="checkbox"
           data-id="{{ $model->id }}"
           data-field="{{ $name }}"
           data-route="{{ route('admin.' . $model->getTable() . '.executeSwitch', ['id' => $model->id]) }}"
           {{ data_get($model, $name) ? 'checked' : '' }}
           id="status_{{ $name }}_{{ $model->id }}" />
</div>
