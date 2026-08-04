{{--
    Generic anonymous Blade component:
        <x-crud.status-bar
            :model="$model"
            :route="route('admin.companies.executeSwitch', $model->id)"
            title="Entreprise active"
            description="Inclure cette entreprise dans la prospection active."
            permission="edit companies"
        />

    Renders a clic2loc-style live toggle card for any boolean field on any model.
    CRITICAL: the toggle route is ALWAYS passed in as a full URL — never inferred.

    Toggle contract (must stay stable so the shared DataTableUtils JS binds correctly):
        class="status-toggle"
        data-field="{{ $field }}"
        data-route="{{ $route }}"
        id="toggle_{$field}_{$model->id}"

    Props:
        model       (object)       — Eloquent model instance (must have ->id)
        field       (string)       — boolean field name on the model; default 'is_active'
        route       (string)       — full URL for the toggle PUT endpoint
        title       (string)       — card heading text
        description (string)       — card body sub-text
        permission  (string|null)  — Spatie permission gate; null = render unconditionally
        success     (string)       — toast message on success
        error       (string)       — toast message on failure
        icon        (string)       — Bootstrap Icons class for the card icon
--}}
@props([
    'model',
    'field'       => 'is_active',
    'route',
    'title'       => '',
    'description' => '',
    'permission'  => null,
    'success'     => 'Mis à jour',
    'error'       => 'Échec de la mise à jour',
    'icon'        => 'bi-check-circle-fill',
])

@php
    $active      = (bool) data_get($model, $field, true);
    $toggleColor = $active ? 'success' : 'secondary';
    $toggleLabel = $title ?: 'Statut actif';
@endphp

@if($permission)
    @can($permission)
        <div class="row g-4 mb-5">
            <div class="col-md-4">
                <div class="notice d-flex h-100 bg-light-{{ $toggleColor }} rounded border-{{ $toggleColor }} border border-dashed p-5">
                    <i class="bi {{ $icon }} fs-2tx text-{{ $toggleColor }} me-4"></i>
                    <div class="d-flex flex-stack flex-grow-1">
                        <div class="fw-semibold">
                            @if($title)
                                <h4 class="text-gray-900 fw-bold mb-1">{{ $title }}</h4>
                            @endif
                            @if($description)
                                <div class="fs-7 text-gray-700">{{ $description }}</div>
                            @endif
                        </div>
                        <div class="form-check form-switch form-check-custom form-check-solid ms-3">
                            <input class="form-check-input h-30px w-50px status-toggle"
                                   type="checkbox"
                                   data-field="{{ $field }}"
                                   data-route="{{ $route }}"
                                   id="toggle_{{ $field }}_{{ $model->id }}"
                                   role="switch"
                                   aria-label="Modifier : {{ $toggleLabel }}"
                                   {{ data_get($model, $field) ? 'checked' : '' }} />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endcan
@else
    <div class="row g-4 mb-5">
        <div class="col-md-4">
            <div class="notice d-flex h-100 bg-light-{{ $toggleColor }} rounded border-{{ $toggleColor }} border border-dashed p-5">
                <i class="bi {{ $icon }} fs-2tx text-{{ $toggleColor }} me-4"></i>
                <div class="d-flex flex-stack flex-grow-1">
                    <div class="fw-semibold">
                        @if($title)
                            <h4 class="text-gray-900 fw-bold mb-1">{{ $title }}</h4>
                        @endif
                        @if($description)
                            <div class="fs-7 text-gray-700">{{ $description }}</div>
                        @endif
                    </div>
                    <div class="form-check form-switch form-check-custom form-check-solid ms-3">
                        <input class="form-check-input h-30px w-50px status-toggle"
                               type="checkbox"
                               data-field="{{ $field }}"
                               data-route="{{ $route }}"
                               id="toggle_{{ $field }}_{{ $model->id }}"
                                   role="switch"
                                   aria-label="Modifier : {{ $toggleLabel }}"
                               {{ data_get($model, $field) ? 'checked' : '' }} />
                    </div>
                </div>
            </div>
        </div>
    </div>
@endif

@once
@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (window.DataTableUtils) {
            DataTableUtils.initializeToggleSwitch({
                successMessage: @json($success),
                errorMessage: @json($error)
            });
        }
    });
</script>
@endpush
@endonce
