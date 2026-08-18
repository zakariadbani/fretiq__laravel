{{--
    ProspectBatch hero action buttons — mirrors prospect_criteria's
    _header-actions.blade.php data-* contract. Both actions require the batch
    to be linked to a criteria (batch-promoted companies inherit criteria_id
    from the batch and are admitted/scored against it — see the Part B
    governing constraint) and a non-draft/queued/running status; the
    controller enforces the same guard (batchActionGuard()) — this is
    cosmetic only.

    Variables: $model (the ProspectBatch), $companyCount (COUNT(DISTINCT company_id)
    for this batch's items — computed once by ProspectBatchController::view() and
    shared with ProspectBatchViewConfig's quick_actions, never recomputed here).
--}}
@php
    $canActOnBatch = $model->prospect_criteria_id !== null
        && ! in_array($model->status, ['draft', 'queued', 'running'], true);
@endphp
@if($canActOnBatch)
    @can('enrich companies')
        <button type="button"
                id="prospect-batch-contact-enrichment-btn"
                class="btn btn-sm btn-light-primary"
                data-preview-url="{{ route('admin.prospect_batches.contact_enrichment_preview', $model->id) }}"
                data-dispatch-url="{{ route('admin.prospect_batches.contact_enrichment_dispatch', $model->id) }}"
                data-csrf-token="{{ csrf_token() }}"
                onclick="launchBatchContactEnrichment(this)">
            <i class="bi bi-person-plus-fill me-1"></i>
            Chercher les contacts manquants
        </button>
    @endcan
    @can('run prospect resolution')
        @php
            $batchCompanyCount = $companyCount ?? (int) $model->items()->whereNotNull('company_id')->distinct('company_id')->count('company_id');
        @endphp
        <button type="button"
                id="prospect-batch-rescore-btn"
                class="btn btn-sm btn-light-warning"
                data-dispatch-url="{{ route('admin.prospect_batches.rescore_dispatch', $model->id) }}"
                data-status-base-url="{{ route('admin.prospect_batches.rescore_status', $model->id) }}"
                data-csrf-token="{{ csrf_token() }}"
                data-company-count="{{ $batchCompanyCount }}"
                onclick="launchBatchRescore(this)">
            <i class="bi bi-arrow-repeat me-1"></i>
            Relancer le scoring IA
        </button>
    @endcan
@endif
