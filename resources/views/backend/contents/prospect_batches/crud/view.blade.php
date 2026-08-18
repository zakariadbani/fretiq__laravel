<x-default-layout>

@section('title', $model->name)

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[
        ['label' => 'Lots', 'route' => 'admin.prospect_batches.index'],
        ['label' => $model->name],
    ]" />
@endsection

@section('toolbar_actions')
    @include('backend.elements.form-actions', ['variant' => 'toolbar', 'backRoute' => 'admin.prospect_batches.index'])
@endsection

{{--
    ProspectBatch view — hero + tabbar + Aperçu/Résultats/À vérifier panes, all
    on this one page (no more separate /review route). $canReview + the review
    tab are both gated server-side (ProspectBatchController::view()) — the tab
    simply doesn't exist in the markup for a user without the permission.
--}}
@php
    $hasReviewPane = ($workspace !== null);
    $liveStatusPercent = $model->total_items > 0 ? (int) floor(($model->processed_items / $model->total_items) * 100) : 0;
    $discoverCursor = is_array($model->source_cursor) ? $model->source_cursor : [];
    $discoverInterrupted = $model->source_type === 'discover'
        && $model->cost_confirmed_at !== null
        && ! in_array($model->status, ['queued', 'running'], true)
        && ($discoverCursor['exhausted'] ?? false) !== true;
    $discoverResults = is_int($discoverCursor['results'] ?? null) ? $discoverCursor['results'] : null;
    $discoverRemaining = $discoverResults !== null ? max(0, $discoverResults - (int) ($discoverCursor['offset'] ?? 0)) : null;
@endphp

{{-- Permanent banners stay visible above the tabs, regardless of the selected pane. --}}
@if(in_array($model->status, ['queued', 'running'], true))
    <div id="prospect-batch-live-status" class="alert alert-primary d-flex align-items-center mb-6" data-status-url="{{ route('admin.prospect_batches.status', $model) }}" aria-live="polite">
        <i class="bi bi-arrow-repeat fs-2 me-3" aria-hidden="true"></i>
        <div class="flex-grow-1">
            <div class="fw-bold">Traitement en cours</div>
            <div class="progress h-8px my-3"><div class="progress-bar bg-primary" data-live-status-bar style="width: {{ $liveStatusPercent }}%"></div></div>
            <div class="fs-8 text-muted" data-live-status-progress>{{ $liveStatusPercent }} % — {{ $model->processed_items }}/{{ $model->total_items }}</div>
            <div class="alert alert-warning d-none mt-3 mb-0 py-2 fs-8" data-live-status-waiting>Le lot attend un worker. Vérifiez que la file « default » est démarrée.</div>
        </div>
    </div>
@endif

@if($discoverInterrupted)
    <div
        class="alert alert-warning d-flex align-items-center mb-6"
        data-discover-resume-banner
        data-resume-url="{{ route('admin.prospect_batches.resume_discovery', $model) }}"
    >
        <i class="bi bi-exclamation-triangle fs-2 me-3" aria-hidden="true"></i>
        <div class="flex-grow-1">
            <div class="fw-bold">La recherche d’entreprises a été interrompue</div>
            <div>
                @if($discoverRemaining !== null)
                    Il reste environ {{ $discoverRemaining }} entreprise{{ $discoverRemaining > 1 ? 's' : '' }} à découvrir.
                @else
                    La recherche ne s’est pas terminée normalement.
                @endif
            </div>
            <div class="fs-8 text-danger mt-1 d-none" data-discover-resume-error></div>
        </div>
        @can('run prospect resolution')
            <button type="button" class="btn btn-sm btn-warning" data-discover-resume-button data-csrf-token="{{ csrf_token() }}">Continuer la découverte</button>
        @endcan
    </div>
@endif

@include('backend.contents.prospect_batches.partials._header-with-tabs', [
    'model' => $model,
    'currentPage' => 'view',
    'actions' => view('backend.contents.prospect_batches.partials._header-actions', ['model' => $model, 'companyCount' => $companyCount])->render(),
])

<div class="tab-content" data-crud-tab-content>

    {{-- ── Tab 1: Aperçu (default active) ─────────────────────────────────── --}}
    <div class="tab-pane fade show active" id="prospect_batch_apercu" role="tabpanel">
        @include('backend.partials.crud._apercu', ['model' => $model, 'config' => $viewConfig])

        @if($reviewItemsCount > 0)
            <div class="alert alert-warning d-flex align-items-center mt-6">
                <i class="bi bi-exclamation-triangle fs-2 me-3"></i>
                <div class="flex-grow-1">Certaines entreprises ou certains domaines demandent votre choix.</div>
                @if($hasReviewPane)
                    <a href="#prospect_batch_review" class="btn btn-sm btn-warning">Ouvrir À revoir</a>
                @endif
            </div>
        @elseif($model->status === 'failed')
            <div class="alert alert-danger mt-6">Le traitement n’a pas pu se terminer. Aucun détail fournisseur sensible n’est affiché ici.</div>
        @endif
    </div>
    {{-- end Aperçu --}}

    {{-- ── Tab 2: Résultats (items table, moved out of Aperçu) ────────────── --}}
    <div class="tab-pane fade" id="prospect_batch_resultats" role="tabpanel">
        @include('backend.contents.prospect_batches.partials._results-tab', [
            'model' => $model,
            'items' => $items,
            'resultsSort' => $resultsSort,
            'resultsDir' => $resultsDir,
            'outcomeBreakdown' => $outcomeBreakdown,
            'presenter' => $presenter,
            'stalledCount' => $stalledCount,
        ])
    </div>
    {{-- end Résultats --}}

    {{-- ── Tab 3: À vérifier (the review workspace, hosted inline) ─────────── --}}
    @if($hasReviewPane)
        <div class="tab-pane fade" id="prospect_batch_review" role="tabpanel">
            @include('backend.contents.prospect_review.partials._workspace', [
                ...$workspace['data'],
                'hostBatchId' => $model->id,
                'reviewPresenter' => $presenter,
                'blockedDrainReady' => $workspace['blockedDrainReady'],
                'stateExplicit' => $workspace['stateExplicit'],
                'model' => $model,
            ])
        </div>
    @endif

</div>
{{-- end tab-content --}}

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-tabs.js') }}"></script>
    @include('backend.contents.prospect_batches.partials._actions-script')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // A-4: a running-batch/discover-resume banner must never blow away an
            // in-progress review session with a full reload. Only suppress the
            // reload while the review pane is the ACTIVE one at call time — a
            // reviewer sitting on Aperçu or Résultats still gets the reload;
            // pane existence alone isn't enough (checked live, not once at load,
            // since the reviewer can switch tabs after the page loads).
            const announceRefreshNeeded = (message) => {
                const reviewPane = document.getElementById('prospect_batch_review');
                const reviewPaneActive = reviewPane !== null && reviewPane.hidden === false;
                if (!reviewPaneActive) {
                    window.location.reload();
                    return;
                }

                let banner = document.getElementById('prospect-batch-refresh-banner');
                if (!banner) {
                    banner = document.createElement('div');
                    banner.id = 'prospect-batch-refresh-banner';
                    banner.className = 'alert alert-success d-flex align-items-center mb-6';
                    banner.setAttribute('role', 'status');
                    banner.setAttribute('aria-live', 'polite');
                    banner.innerHTML = '<i class="bi bi-check-circle fs-2 me-3" aria-hidden="true"></i>'
                        + '<div class="flex-grow-1" data-refresh-banner-message></div>'
                        + '<button type="button" class="btn btn-sm btn-light-success text-nowrap" data-refresh-banner-action>Actualiser</button>';
                    const anchor = document.querySelector('[data-crud-tab-content]');
                    anchor?.parentNode?.insertBefore(banner, anchor);
                    banner.querySelector('[data-refresh-banner-action]').addEventListener('click', () => window.location.reload());
                }
                banner.querySelector('[data-refresh-banner-message]').textContent = message;
            };

            const liveStatus = document.getElementById('prospect-batch-live-status');
            if (liveStatus) {
                const statusUrl = liveStatus.dataset.statusUrl;
                const bar = liveStatus.querySelector('[data-live-status-bar]');
                const progressText = liveStatus.querySelector('[data-live-status-progress]');
                const waitingNode = liveStatus.querySelector('[data-live-status-waiting]');
                const delays = [3000, 5000, 10000, 15000];
                let attempt = 0;

                const poll = async () => {
                    try {
                        const response = await fetch(statusUrl, {
                            headers: { Accept: 'application/json' },
                            credentials: 'same-origin',
                        });
                        if (!response.ok) throw new Error('status_failed');
                        const payload = await response.json();
                        const percent = Math.max(0, Math.min(100, Number(payload.progress?.percent || 0)));
                        if (bar) bar.style.width = percent + '%';
                        if (progressText) progressText.textContent = percent + ' % — ' + Number(payload.progress?.processed || 0) + '/' + Number(payload.progress?.total || 0);
                        waitingNode?.classList.toggle('d-none', payload.worker_waiting !== true);
                        if (payload.terminal) {
                            announceRefreshNeeded('Traitement terminé — actualisez pour voir les derniers résultats.');
                            return;
                        }
                        attempt += 1;
                        window.setTimeout(poll, delays[Math.min(attempt, delays.length - 1)]);
                    } catch (error) {
                        window.setTimeout(poll, 15000);
                    }
                };

                if (statusUrl) {
                    window.setTimeout(poll, delays[0]);
                }
            }

            const resumeButton = document.querySelector('[data-discover-resume-button]');
            if (resumeButton) {
                const banner = resumeButton.closest('[data-discover-resume-banner]');
                const errorNode = banner?.querySelector('[data-discover-resume-error]');
                const resumeUrl = banner?.dataset.resumeUrl;
                const originalLabel = resumeButton.textContent;

                const resumeFailureMessage = (status, code) => {
                    if (status === 403) return 'Vous n’avez pas la permission de reprendre cette découverte.';
                    if (code === 'prospect_discover_not_resumable') return 'Ce lot ne peut plus être repris : la recherche est déjà complète ou n’est plus reprenable.';
                    if (code === 'prospect_discover_resume_criteria_stale') return 'Le critère a changé depuis (une nouvelle découverte a été lancée sur ce critère). Ce lot ne peut plus être repris.';
                    return 'La reprise n’a pas pu être lancée. Réessayez plus tard.';
                };

                resumeButton.addEventListener('click', async () => {
                    if (resumeButton.disabled || !resumeUrl) return;
                    resumeButton.disabled = true;
                    resumeButton.textContent = 'Reprise en cours…';
                    errorNode?.classList.add('d-none');

                    try {
                        const response = await fetch(resumeUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                Accept: 'application/json',
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': resumeButton.dataset.csrfToken,
                            },
                            body: JSON.stringify({}),
                        });
                        const payload = await response.json().catch(() => ({}));
                        if (!response.ok) {
                            if (errorNode) {
                                errorNode.textContent = resumeFailureMessage(response.status, payload.code);
                                errorNode.classList.remove('d-none');
                            }
                            resumeButton.disabled = false;
                            resumeButton.textContent = originalLabel;
                            return;
                        }
                        announceRefreshNeeded('Reprise de la découverte lancée — actualisez pour suivre l’avancement.');
                    } catch (error) {
                        if (errorNode) {
                            errorNode.textContent = 'La reprise n’a pas pu être lancée. Réessayez plus tard.';
                            errorNode.classList.remove('d-none');
                        }
                        resumeButton.disabled = false;
                        resumeButton.textContent = originalLabel;
                    }
                });
            }
        });
    </script>
@endpush

</x-default-layout>
