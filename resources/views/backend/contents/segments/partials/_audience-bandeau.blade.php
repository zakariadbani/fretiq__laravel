{{--
    Audience bandeau — lightweight in-form preview strip.
    Positioned directly below the Ciblage card (cause→effect).
    Reuses the SAME element IDs as segment-form.js so POST /admin/segments/preview
    AJAX continues to work without any JS changes.

    Layout: big count LEFT · funnel chips CENTER · summary RIGHT
    The segment_preview_card id is retained so segment-form.js can read data-preview-url.
--}}
<div id="segment_preview_card"
     class="border border-dashed border-gray-300 rounded p-5 mb-5 bg-light-primary bg-opacity-25"
     data-preview-url="{{ route('admin.segments.preview') }}">

    <div class="d-flex align-items-center gap-5 flex-wrap">

        {{-- Big count (left) --}}
        <div class="d-flex flex-column align-items-center flex-shrink-0" style="min-width:80px;">
            <div id="preview_final"
                 class="fs-2hx fw-bolder text-gray-900 lh-1"
                 aria-live="polite">—</div>
            <div class="text-muted fs-8 mt-1">destinataires</div>
            <div id="preview_parked" class="text-warning fs-8 fw-semibold mt-1 d-none"></div>
        </div>

        {{-- Separator --}}
        <div class="vr d-none d-sm-block text-gray-300"></div>

        {{-- Funnel chips (center, flex-grow) --}}
        <div class="flex-grow-1 min-w-0">

            {{-- Loading spinner --}}
            <div id="preview_loading" class="d-none">
                <span class="spinner-border spinner-border-sm text-primary" role="status"></span>
                <span class="text-muted fs-7 ms-1">Calcul en cours…</span>
            </div>

            {{-- Error badge --}}
            <div id="preview_error" class="d-none">
                <span class="badge badge-light-danger fs-7">
                    <i class="bi bi-exclamation-circle me-1"></i>
                    Aperçu indisponible
                </span>
            </div>

            {{-- Funnel chips rendered by segment-form.js into this div --}}
            <div id="preview_funnel" class="d-flex flex-wrap gap-2 align-items-center">
                {{-- JS populates: <span class="badge badge-light-...">label : value</span> --}}
            </div>

            {{-- Cold-gate warning --}}
            <div id="preview_warning" class="alert alert-warning d-none py-2 px-3 fs-7 mt-2 mb-0" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <span class="warning-text">L'envoi à froid est désactivé — ce segment ne recevra aucun email.</span>
            </div>

        </div>

        {{-- Separator --}}
        <div class="vr d-none d-md-block text-gray-300"></div>

        {{-- Summary sentence (right) --}}
        <div class="flex-shrink-0 text-muted fs-7" style="max-width:220px;">
            <div id="preview_summary"></div>
        </div>

    </div>

</div>
{{-- NOTE: #preview_sample / #preview_sample_body are intentionally absent —
     the contacts table (below) replaces the old sample. renderSample() is no-op'd in segment-form.js. --}}
