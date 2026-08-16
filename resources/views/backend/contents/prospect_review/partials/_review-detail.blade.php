{{--
    Right-pane content for the review queue. Included by index.blade.php (full page load)
    and rendered directly by ProspectReviewController::index() for `?partial=1` fetches
    (the async queue-selection swap) — same conditional either way, so a re-selected item
    that's no longer eligible falls back to the same empty state a full reload would show.

    $monitorOutcome / $hasActiveFilter are computed by index.blade.php's top @php block and
    inherited automatically when this is @include'd from there (Blade shares the parent
    view's variables with includes). The partial=1 controller branch never passes them —
    the queue-entry links this drives never carry monitor_item, so the ?? fallbacks below
    always resolve to the plain "nothing to review" state for that path, which is correct.
--}}
@if($activeItem && $activeReview)
    @include('backend.contents.prospect_review.partials._company-card', ['item' => $activeItem, 'review' => $activeReview])
@elseif(($monitorOutcome ?? null) && $monitorOutcome['terminal'] && (int) $filters['item'] === (int) ($monitoredItem ?? null)?->id)
    <div class="prospect-review-empty" data-review-monitored-result-placeholder>
        <i class="bi bi-arrow-up-circle text-primary" aria-hidden="true"></i>
        <h3 class="fs-4 mt-4">Résultat affiché ci-dessus</h3>
        <p class="text-muted mb-0">Choisissez explicitement l’action suivante dans le résultat de {{ $monitoredItem->company_name }}.</p>
    </div>
@else
    <div class="prospect-review-empty" data-review-empty>
        <i class="bi bi-check-circle text-success" aria-hidden="true"></i>
        <h3 class="fs-4 mt-4">Aucune entreprise à vérifier</h3>
        <p class="text-muted mb-4">{{ ($hasActiveFilter ?? false) ? 'Aucun résultat pour les filtres actuels.' : 'Toutes les décisions visibles ont été traitées.' }}</p>
        <a class="btn btn-light-primary" href="{{ ($hasActiveFilter ?? false) ? route('admin.prospect_review.index', ['tab' => 'companies', 'state' => 'all']) : route('admin.prospect_batches.index') }}">{{ ($hasActiveFilter ?? false) ? 'Effacer les filtres' : 'Voir les lots' }}</a>
    </div>
@endif
