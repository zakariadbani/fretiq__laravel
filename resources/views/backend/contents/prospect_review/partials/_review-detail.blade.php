{{--
    Right-pane content for the review queue, hosted inline on the batch view page. Included
    by the _workspace partial (full page load) and rendered directly by
    ProspectBatchController::view() for `?partial=1` fetches (the async queue-selection swap)
    — same conditional either way. A re-selected item that's no longer eligible falls back to
    another visible item (ProspectReviewWorkspace::build), EXCEPT when the requested item is
    the monitored one (a retry redirect): that fallback is suppressed there, so this partial
    renders the monitored-result placeholder below, or the plain empty state until the retry
    resolves.

    $monitorOutcome / $hasActiveFilter are computed by _workspace.blade.php's top @@php block
    and inherited automatically when this is @include'd from there (Blade shares the parent
    view's variables with includes). The partial=1 controller branch passes
    reviewPresenter/hostBatchId explicitly but not $monitorOutcome/$hasActiveFilter — the ??
    fallbacks below always resolve to the plain "nothing to review" (no-filter) copy for that
    path. Known limitation: if a filter IS active on a partial=1 fetch, this still renders the
    no-filter copy/link instead of "Effacer les filtres" — accepted for now since threading
    $hasActiveFilter through the partial path is a bigger change than warranted here.
--}}
@if($activeItem && $activeReview)
    @include('backend.contents.prospect_review.partials._company-card', ['item' => $activeItem, 'review' => $activeReview])
@elseif(($monitorOutcome ?? null) && ! $monitorOutcome['terminal'] && (int) $filters['item'] === (int) ($monitoredItem ?? null)?->id)
    <div class="prospect-review-empty" data-review-monitored-pending-placeholder>
        <i class="bi bi-hourglass-split text-primary" aria-hidden="true"></i>
        <h3 class="fs-4 mt-4">Relance en cours</h3>
        <p class="text-muted mb-0">Le résultat de {{ $monitoredItem->company_name }} s’affichera ici dès que le traitement sera terminé.</p>
    </div>
@elseif(($monitorOutcome ?? null) && $monitorOutcome['terminal'] && (int) $filters['item'] === (int) ($monitoredItem ?? null)?->id)
    <div class="prospect-review-empty" data-review-monitored-result-placeholder>
        <i class="bi bi-arrow-up-circle text-primary" aria-hidden="true"></i>
        <h3 class="fs-4 mt-4">Résultat affiché ci-dessus</h3>
        <p class="text-muted mb-0">Choisissez explicitement l’action suivante dans le résultat de {{ $monitoredItem->company_name }}.</p>
    </div>
@else
    @php
        $emptyStateUrl = ($hasActiveFilter ?? false)
            ? $reviewPresenter->workspaceUrl($hostBatchId, ['state' => 'all'])
            : route('admin.prospect_batches.view', ['id' => $hostBatchId]);
        $emptyStateLabel = ($hasActiveFilter ?? false) ? 'Effacer les filtres' : 'Revenir au lot';
    @endphp
    <div class="prospect-review-empty" data-review-empty>
        <i class="bi bi-check-circle text-success" aria-hidden="true"></i>
        <h3 class="fs-4 mt-4">Aucune entreprise à vérifier</h3>
        <p class="text-muted mb-4">{{ ($hasActiveFilter ?? false) ? 'Aucun résultat pour les filtres actuels.' : 'Toutes les décisions visibles ont été traitées.' }}</p>
        <a class="btn btn-light-primary" href="{{ $emptyStateUrl }}">{{ $emptyStateLabel }}</a>
    </div>
@endif
