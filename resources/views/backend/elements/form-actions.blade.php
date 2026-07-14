@php
    $variant         = $variant ?? 'sticky';
    $backParams      = $backParams ?? [];
    $saveAndContinue = $saveAndContinue ?? true;
    $isToolbar       = $variant === 'toolbar';
    $sizeCls         = $isToolbar ? 'btn-sm fw-bold ' : '';
    $backLabel       = $isToolbar ? 'Retour à la liste' : 'Retour';
    $showSaveActions = !$isToolbar;
@endphp

@unless($isToolbar)
<div class="sticky-bottom bg-body border-top shadow-sm py-4 mt-4" data-crud-form-actions="sticky">
    <div class="container-fluid">
        <div class="d-flex justify-content-end flex-wrap gap-2 gap-md-3">
@endunless

    <a href="{{ route($backRoute, $backParams) }}" class="btn {{ $sizeCls }}btn-light btn-active-light-primary">
        <i class="bi bi-arrow-left fs-4{{ $isToolbar ? '' : ' me-1' }}"></i>
        {{ $backLabel }}
    </a>

    @if($showSaveActions && $saveAndContinue)
    <button type="button" name="saveandcontinue" class="btn {{ $sizeCls }}btn-success submit">
        <span class="indicator-label">
            <i class="bi bi-check-circle fs-4 me-1"></i>
            Enregistrer et rester
        </span>
        <span class="indicator-progress">
            Veuillez patienter...
            <span class="spinner-border spinner-border-sm align-middle ms-2"></span>
        </span>
    </button>
    @endif

    @if($showSaveActions)
    <button type="button" name="save" class="btn {{ $sizeCls }}btn-primary submit">
        <span class="indicator-label">
            <i class="bi bi-check-lg fs-4 me-1"></i>
            Enregistrer la fiche
        </span>
        <span class="indicator-progress">
            Veuillez patienter...
            <span class="spinner-border spinner-border-sm align-middle ms-2"></span>
        </span>
    </button>
    @endif

@unless($isToolbar)
        </div>
    </div>
</div>
@endunless
