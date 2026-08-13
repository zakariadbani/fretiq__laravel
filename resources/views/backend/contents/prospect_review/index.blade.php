<x-default-layout>
    @section('title', 'À revoir')

    @section('breadcrumbs')
        <x-crud.breadcrumb :items="[['label' => 'Prospection'], ['label' => 'À revoir']]" />
    @endsection

    @php
        $handledCompanies = (int) $summary['handled'];
        $pendingCompanies = (int) $summary['companies_pending'];
        $companyTotal = $handledCompanies + $pendingCompanies;
        $companyProgress = $companyTotal > 0 ? min(100, (int) round(($handledCompanies / $companyTotal) * 100)) : 100;
        $queryValue = static fn ($value): bool => $value !== null && $value !== '';
        $monitorOutcome = isset($monitoredItem) && $monitoredItem !== null
            ? $reviewPresenter->retryOutcome($monitoredItem)
            : null;
        $hasActiveFilter = $filters['batch'] !== null || $filters['reason'] !== null || $filters['state'] !== 'all' || $filters['q'] !== '' || $filters['item'] !== null;
    @endphp

    <main class="prospect-review" data-review-workspace>
        @if($monitorOutcome)
            @php
                $monitorShowsDetail = in_array($monitorOutcome['status'], ['review', 'failed'], true);
                $monitorReviewUrl = route('admin.prospect_review.index', [
                    'tab' => 'companies',
                    'batch' => $monitoredItem->prospect_batch_id,
                    'item' => $monitoredItem->id,
                    'monitor_item' => $monitoredItem->id,
                ]);
            @endphp
            <div
                class="alert alert-{{ $monitorOutcome['level'] }} d-flex align-items-start mb-6"
                data-review-monitor="{{ $monitoredItem->id }}"
                data-review-monitor-status-url="{{ route('admin.prospect_review.items.status', $monitoredItem) }}"
                data-review-monitor-terminal="{{ $monitorOutcome['terminal'] ? '1' : '0' }}"
                data-review-monitor-timeout-message="Cette relance est toujours dans la file. Ne la soumettez pas à nouveau."
                tabindex="-1"
                role="{{ $monitorOutcome['level'] === 'danger' ? 'alert' : 'status' }}"
                aria-live="polite"
                data-review-monitor-result
            >
                <i
                    class="bi {{ $monitorOutcome['terminal'] ? ($monitorOutcome['level'] === 'success' ? 'bi-check-circle' : 'bi-exclamation-triangle') : 'bi-arrow-repeat' }} fs-2 me-3"
                    data-review-monitor-icon
                    aria-hidden="true"
                ></i>
                <div>
                    <div class="fw-bold" data-review-monitor-title>{{ $monitorOutcome['title'] }}</div>
                    <div data-review-monitor-message>{{ $monitorOutcome['message'] }}</div>
                    @if($monitorOutcome['terminal'] && $monitorOutcome['level'] === 'success' && $monitoredFacts)
                        <div class="d-grid gap-1 text-muted fs-8 mt-2" data-review-monitor-facts>
                            @if($monitoredFacts['provider_result_count'] !== null)
                                <div data-review-monitor-provider-results>
                                    {{ $monitoredFacts['provider_result_count'] === 1 ? 'Adresse retournée' : 'Adresses retournées' }} : {{ $monitoredFacts['provider_result_count'] }}
                                </div>
                            @endif
                            @if($monitoredFacts['recorded_units'] !== null)
                                <div data-review-monitor-recorded-units>
                                    {{ (float) $monitoredFacts['recorded_units'] === 1.0 ? 'Unité enregistrée par Fretiq' : 'Unités enregistrées par Fretiq' }} : {{ $monitoredFacts['recorded_units'] + 0 }}
                                </div>
                            @endif
                            <div data-review-monitor-imported-contacts>
                                Contacts importés : {{ $monitoredFacts['imported_contacts_count'] }}. L’import ne déclenche aucun envoi.
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-3" data-review-monitor-actions>
                            <a class="btn btn-light btn-sm" href="{{ $nextMonitoredItem ? route('admin.prospect_review.index', ['tab' => 'companies', 'batch' => $nextMonitoredItem->prospect_batch_id, 'item' => $nextMonitoredItem->id]) : route('admin.prospect_review.index', ['tab' => 'companies', 'batch' => $filters['batch']]) }}">Continuer la file</a>
                        </div>
                    @else
                        <div class="text-muted fs-8 mt-2" data-review-monitor-facts></div>
                    @endif
                    <a
                        class="alert-link mt-2 {{ $monitorShowsDetail ? 'd-inline-block' : 'd-none' }}"
                        href="{{ $monitorReviewUrl }}"
                        data-review-monitor-detail
                    >Voir le détail</a>
                </div>
            </div>
        @endif

        @foreach(['success' => ['success', 'bi-check-circle'], 'info' => ['info', 'bi-info-circle'], 'warning' => ['warning', 'bi-exclamation-triangle'], 'error' => ['danger', 'bi-x-octagon']] as $feedbackKey => [$feedbackTone, $feedbackIcon])
            @if(session($feedbackKey))
                <div class="alert alert-{{ $feedbackTone }} d-flex align-items-start mb-6" data-review-feedback="{{ $feedbackKey }}" role="{{ $feedbackKey === 'error' ? 'alert' : 'status' }}">
                    <i class="bi {{ $feedbackIcon }} fs-2 me-3" aria-hidden="true"></i>
                    <div>
                        <div class="fw-bold">{{ match($feedbackKey) { 'success' => 'Décision enregistrée', 'info' => 'Import automatique', 'warning' => 'File actualisée', default => 'Action impossible' } }}</div>
                        <div>{{ session($feedbackKey) }}</div>
                    </div>
                </div>
            @endif
        @endforeach

        <section class="card mb-6" data-review-stepper>
            <div class="card-body p-5 p-lg-6">
                <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-5">
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            @if($activeReview && $activeReview['issue']['kind'] === 'interrupted')
                                <span class="badge badge-light-danger">Traitement interrompu</span>
                                <span class="text-muted fs-7">Intervention requise</span>
                            @else
                                <span class="badge badge-light-warning">Vérification requise</span>
                                <span class="text-muted fs-7">Décision humaine</span>
                            @endif
                        </div>
                        <h2 class="fs-2 mb-2">Vérifier les entreprises</h2>
                        <p class="text-muted mb-0">Concentrez-vous sur le premier point orange ou rouge. Les contacts sont importés automatiquement après résolution de l’entreprise, sans déclencher d’email.</p>
                    </div>

                    <div class="prospect-review-progress" aria-label="Progression des vérifications">
                            <div class="d-flex justify-content-between gap-5 mb-2">
                                <span class="fw-semibold">Progression</span>
                                <span class="text-muted">{{ $handledCompanies }} / {{ $companyTotal }} vérifiées</span>
                            </div>
                            <div class="progress h-6px" role="progressbar" aria-valuenow="{{ $companyProgress }}" aria-valuemin="0" aria-valuemax="100">
                                <div class="progress-bar bg-success" style="width: {{ $companyProgress }}%"></div>
                            </div>
                            <div class="text-muted fs-8 mt-2">{{ $pendingCompanies }} entreprise(s) attendent encore une décision.</div>
                    </div>
                </div>
            </div>
        </section>

        <section class="card">
            <div class="card-body p-0">
                <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2 px-5 px-lg-6 py-4 border-bottom">
                    <div class="fw-semibold">Entreprises à vérifier <span class="badge badge-light-warning ms-2">{{ $summary['companies_pending'] }}</span></div>
                    <div class="text-muted fs-8">Contacts importés dans les lots affichés : {{ $summary['imported_contacts'] }}</div>
                </div>

                <div id="review-queue" tabindex="-1">
                    <div class="prospect-review-shell">
                            <aside class="prospect-review-queue" aria-label="Entreprises à vérifier">
                                <div class="prospect-review-queue-header">
                                    <div class="d-flex align-items-center justify-content-between gap-3 mb-4">
                                        <div>
                                            <h3 class="fs-5 mb-1">File de vérification</h3>
                                            <div class="text-muted fs-8">{{ $items->total() }} résultat(s) avec ces filtres</div>
                                        </div>
                                        <span class="badge badge-light-warning">{{ $items->total() }}</span>
                                    </div>

                                    <form method="GET" class="prospect-review-filter">
                                        <input type="hidden" name="tab" value="companies">
                                        <input type="hidden" name="state" value="{{ $filters['state'] }}">
                                        <label class="prospect-review-search-label" for="review-search">
                                            <span class="visually-hidden">Entreprise</span>
                                            <i class="bi bi-search" aria-hidden="true"></i>
                                            <input class="form-control form-control-sm" id="review-search" name="q" value="{{ $filters['q'] }}" placeholder="Nom, ville ou pays">
                                        </label>
                                        @if($filters['batch'])<input type="hidden" name="batch" value="{{ $filters['batch'] }}">@endif
                                        @if($filters['reason'])<input type="hidden" name="reason" value="{{ $filters['reason'] }}">@endif
                                    </form>

                                    <div class="prospect-review-state-filters" aria-label="Filtrer la file par état">
                                        @foreach(['all' => 'Toutes', 'attention' => 'Attention', 'blocked' => 'Bloquées'] as $stateValue => $stateLabel)
                                            <a
                                                class="prospect-review-state-filter @if($filters['state'] === $stateValue) is-active @endif"
                                                data-review-state-filter="{{ $stateValue }}"
                                                @if($filters['state'] === $stateValue) aria-current="true" @endif
                                                href="{{ route('admin.prospect_review.index', array_filter(['batch' => $filters['batch'], 'tab' => 'companies', 'reason' => $filters['reason'], 'state' => $stateValue === 'all' ? null : $stateValue, 'q' => $filters['q']], $queryValue)) }}"
                                            >{{ $stateLabel }}</a>
                                        @endforeach
                                    </div>

                                    <details class="prospect-review-more-filters mt-3" @if($filters['batch'] || $filters['reason']) open @endif>
                                        <summary>Filtres avancés</summary>
                                        <form method="GET" class="prospect-review-advanced-filter mt-3">
                                            <input type="hidden" name="tab" value="companies">
                                            <input type="hidden" name="state" value="{{ $filters['state'] }}">
                                            @if($filters['q'] !== '')<input type="hidden" name="q" value="{{ $filters['q'] }}">@endif
                                            <label class="form-label fs-8" for="review-batch">Lot</label>
                                            <select class="form-select form-select-sm mb-3" id="review-batch" name="batch">
                                                <option value="">Tous les lots</option>
                                                @foreach($batches as $batch)<option value="{{ $batch->id }}" @selected($filters['batch'] === $batch->id)>{{ $batch->name }}</option>@endforeach
                                            </select>
                                            <label class="form-label fs-8" for="review-reason">Point à vérifier</label>
                                            <select class="form-select form-select-sm mb-3" id="review-reason" name="reason">
                                                <option value="">Tous les points</option>
                                                @foreach($reviewPresenter->reasonOptions() as $code => $reason)<option value="{{ $code }}" @selected($filters['reason'] === $code)>{{ $reason['label'] }}</option>@endforeach
                                            </select>
                                            <div class="d-flex gap-2">
                                                <button class="btn btn-sm btn-light-primary flex-grow-1">Appliquer</button>
                                                <a class="btn btn-sm btn-light" href="{{ route('admin.prospect_review.index', ['tab' => 'companies']) }}">Effacer</a>
                                            </div>
                                        </form>
                                    </details>
                                </div>

                                @if($items->isNotEmpty())
                                    <form method="GET" class="prospect-review-mobile-selector">
                                        <input type="hidden" name="tab" value="companies">
                                        @if($filters['batch'])<input type="hidden" name="batch" value="{{ $filters['batch'] }}">@endif
                                        @if($filters['reason'])<input type="hidden" name="reason" value="{{ $filters['reason'] }}">@endif
                                        @if($filters['state'] !== 'all')<input type="hidden" name="state" value="{{ $filters['state'] }}">@endif
                                        @if($filters['q'] !== '')<input type="hidden" name="q" value="{{ $filters['q'] }}">@endif
                                        @if($items->currentPage() > 1)<input type="hidden" name="companies_page" value="{{ $items->currentPage() }}">@endif
                                        <label class="form-label" for="review-company-selector">Entreprise affichée</label>
                                        <div class="d-flex gap-2">
                                            <select class="form-select" id="review-company-selector" name="item">
                                                @foreach($items as $queueItem)
                                                    <option value="{{ $queueItem->id }}" @selected($activeItem?->id === $queueItem->id)>{{ $queueItem->company_name }}</option>
                                                @endforeach
                                            </select>
                                            <button class="btn btn-light-primary">Afficher</button>
                                        </div>
                                    </form>
                                @endif

                                <div class="prospect-review-queue-list">
                                    @foreach($items as $queueItem)
                                        @php
                                            $queueReview = $reviewPresenter->companyDecision($queueItem);
                                            $queueUrl = route('admin.prospect_review.index', array_filter([
                                                'batch' => $filters['batch'],
                                                'tab' => 'companies',
                                                'reason' => $filters['reason'],
                                                'state' => $filters['state'] === 'all' ? null : $filters['state'],
                                                'q' => $filters['q'],
                                                'item' => $queueItem->id,
                                                'companies_page' => $items->currentPage() > 1 ? $items->currentPage() : null,
                                            ], $queryValue));
                                        @endphp
                                        <a
                                            href="{{ $queueUrl }}"
                                            class="prospect-review-queue-entry @if($activeItem?->id === $queueItem->id) is-active @endif"
                                            data-review-company-queue-entry="{{ $queueItem->id }}"
                                            @if($activeItem?->id === $queueItem->id) aria-current="true" @endif
                                        >
                                            <span class="prospect-review-queue-dot bg-{{ $queueReview['issue']['color'] }}" data-review-queue-dot aria-hidden="true"></span>
                                            <span class="prospect-review-queue-copy">
                                                <span class="d-block fw-bold text-gray-900 text-truncate">{{ $queueItem->company_name }}</span>
                                                <span class="d-block text-muted fs-8 text-truncate">{{ $queueReview['issue']['label'] }}</span>
                                            </span>
                                            <span class="prospect-review-queue-count" aria-label="{{ $queueReview['attention_count'] }} point(s) à examiner">{{ $queueReview['attention_count'] }}</span>
                                        </a>
                                    @endforeach
                                </div>

                                @if($items->hasPages())
                                    <div class="prospect-review-queue-pagination">{{ $items->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
                                @endif
                            </aside>

                            <section class="prospect-review-detail" aria-live="polite">
                                @if($activeItem && $activeReview)
                                    @include('backend.contents.prospect_review.partials._company-card', ['item' => $activeItem, 'review' => $activeReview])
                                @elseif($monitorOutcome && $monitorOutcome['terminal'] && (int) $filters['item'] === (int) $monitoredItem?->id)
                                    <div class="prospect-review-empty" data-review-monitored-result-placeholder>
                                        <i class="bi bi-arrow-up-circle text-primary" aria-hidden="true"></i>
                                        <h3 class="fs-4 mt-4">Résultat affiché ci-dessus</h3>
                                        <p class="text-muted mb-0">Choisissez explicitement l’action suivante dans le résultat de {{ $monitoredItem->company_name }}.</p>
                                    </div>
                                @else
                                    <div class="prospect-review-empty" data-review-empty>
                                        <i class="bi bi-check-circle text-success" aria-hidden="true"></i>
                                        <h3 class="fs-4 mt-4">Aucune entreprise à vérifier</h3>
                                        <p class="text-muted mb-4">{{ $hasActiveFilter ? 'Aucun résultat pour les filtres actuels.' : 'Toutes les décisions visibles ont été traitées.' }}</p>
                                        <a class="btn btn-light-primary" href="{{ $hasActiveFilter ? route('admin.prospect_review.index', ['tab' => 'companies']) : route('admin.prospect_batches.index') }}">{{ $hasActiveFilter ? 'Effacer les filtres' : 'Voir les lots' }}</a>
                                    </div>
                                @endif
                            </section>
                    </div>
                </div>
            </div>
        </section>
    </main>

    @push('styles')
        <style>
            .prospect-review-progress { width: min(100%, 24rem); }
            .prospect-review-shell { display: grid; grid-template-columns: 320px minmax(0, 1fr); min-height: 42rem; }
            .prospect-review-queue { border-right: 1px solid var(--bs-gray-200); background: var(--bs-gray-100); min-width: 0; }
            .prospect-review-queue-header { padding: 1.5rem; border-bottom: 1px solid var(--bs-gray-200); }
            .prospect-review-filter { display: block; }
            .prospect-review-search-label { position: relative; display: block; }
            .prospect-review-search-label > i { position: absolute; z-index: 1; top: 50%; left: .85rem; transform: translateY(-50%); color: var(--bs-gray-500); }
            .prospect-review-search-label .form-control { padding-left: 2.35rem; }
            .prospect-review-state-filters { display: flex; gap: .35rem; margin-top: .75rem; overflow-x: auto; }
            .prospect-review-state-filter { flex: 0 0 auto; padding: .42rem .7rem; border: 1px solid transparent; border-radius: .55rem; color: var(--bs-gray-600); font-size: .75rem; font-weight: 600; }
            .prospect-review-state-filter:hover { background: var(--bs-white); color: var(--bs-primary); }
            .prospect-review-state-filter.is-active { border-color: var(--bs-primary-light); background: var(--bs-primary-light); color: var(--bs-primary); }
            .prospect-review-more-filters > summary { color: var(--bs-gray-600); cursor: pointer; font-size: .75rem; font-weight: 600; }
            .prospect-review-advanced-filter { padding-top: .75rem; border-top: 1px solid var(--bs-gray-200); }
            .prospect-review-queue-list { display: grid; max-height: 56rem; padding: .75rem; gap: .45rem; overflow-y: auto; }
            .prospect-review-queue-entry { display: grid; grid-template-columns: .65rem minmax(0, 1fr) auto; align-items: center; gap: .75rem; width: 100%; min-width: 0; overflow: hidden; padding: .9rem; border: 1px solid transparent; border-radius: .75rem; color: inherit; background: transparent; transition: background-color .15s ease, border-color .15s ease, box-shadow .15s ease; }
            .prospect-review-queue-entry:hover { background: var(--bs-white); border-color: var(--bs-gray-300); color: inherit; }
            .prospect-review-queue-entry.is-active { background: var(--bs-white); border-color: var(--bs-primary); box-shadow: 0 .25rem 1rem rgba(0, 0, 0, .06); }
            .prospect-review-queue-dot { width: .6rem; height: .6rem; border-radius: 50%; box-shadow: 0 0 0 .22rem rgba(152, 162, 179, .12); }
            .prospect-review-queue-copy { display: block; min-width: 0; overflow: hidden; }
            .prospect-review-queue-count { display: inline-flex; min-width: 1.6rem; height: 1.6rem; align-items: center; justify-content: center; padding-inline: .4rem; border-radius: 999px; background: var(--bs-gray-200); color: var(--bs-gray-700); font-size: .72rem; font-weight: 700; }
            .prospect-review-queue-pagination { padding: 1rem 1.5rem; overflow-x: auto; }
            .prospect-review-queue-pagination nav > .d-sm-flex { justify-content: center !important; }
            .prospect-review-queue-pagination nav > .d-sm-flex > div:first-child { display: none; }
            .prospect-review-queue-pagination .pagination { flex-wrap: nowrap; justify-content: flex-start; }
            .prospect-review-queue-pagination .page-item { margin-right: .2rem; }
            .prospect-review-queue-pagination .page-link { min-width: 2rem; height: 2rem; font-size: .85rem; }
            .prospect-review-mobile-selector { display: none; padding: 1.25rem; border-bottom: 1px solid var(--bs-gray-200); background: var(--bs-white); }
            .prospect-review-detail { min-width: 0; padding: 1.5rem; }
            .prospect-review-checks { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .75rem; }
            .prospect-review-check { min-width: 0; padding: 1rem; border: 1px solid var(--bs-gray-200); border-radius: .75rem; background: var(--bs-gray-100); }
            .prospect-review-check-icon { display: inline-flex; width: 1.8rem; height: 1.8rem; align-items: center; justify-content: center; border-radius: 50%; }
            .prospect-review-decision-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
            .prospect-review-candidate { border: 1px solid var(--bs-primary); border-radius: .9rem; background: var(--bs-primary-light); }
            .prospect-review-domain { overflow-wrap: anywhere; }
            .prospect-review-disclosure { border: 1px solid var(--bs-gray-200); border-radius: .75rem; background: var(--bs-white); }
            .prospect-review-disclosure > summary { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem; cursor: pointer; font-weight: 600; list-style: none; }
            .prospect-review-disclosure > summary::-webkit-details-marker { display: none; }
            .prospect-review-disclosure > summary::after { content: '\F282'; font-family: bootstrap-icons; color: var(--bs-gray-600); transition: transform .15s ease; }
            .prospect-review-disclosure[open] > summary::after { transform: rotate(180deg); }
            .prospect-review-disclosure-body { padding: 0 1rem 1rem; }
            [data-review-workspace] [data-review-primary-action]:focus-visible,
            [data-review-workspace] [data-review-defer]:focus-visible,
            [data-review-workspace] [data-review-company-actions] .btn:focus-visible,
            [data-review-workspace] .prospect-review-queue-entry:focus-visible,
            [data-review-workspace] .prospect-review-disclosure > summary:focus-visible {
                outline-style: solid !important;
                outline-width: 3px !important;
                outline-color: var(--bs-primary) !important;
                outline-offset: 3px !important;
                box-shadow: 0 0 0 2px var(--bs-white), 0 0 0 5px var(--bs-primary) !important;
            }
            .prospect-review-empty { display: flex; min-height: 24rem; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 2rem; }
            .prospect-review-empty > i { font-size: 3rem; }
            .prospect-review-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1.5rem; }

            @media (max-width: 991.98px) {
                .prospect-review-shell { grid-template-columns: 1fr; }
                .prospect-review-queue { border-right: 0; border-bottom: 1px solid var(--bs-gray-200); }
                .prospect-review-queue-list, .prospect-review-queue-pagination { display: none; }
                .prospect-review-mobile-selector { display: block; }
                .prospect-review-checks { grid-template-columns: repeat(2, minmax(0, 1fr)); }
                .prospect-review-decision-grid, .prospect-review-grid { grid-template-columns: 1fr; }
            }

            @media (max-width: 575.98px) {
                .prospect-review-detail { padding: 1rem; }
                .prospect-review-checks { grid-template-columns: 1fr; }
                .prospect-review-mobile-selector .d-flex { align-items: stretch; flex-direction: column; }
                [data-review-company-actions] .btn { width: 100%; }
                [data-review-monitor-actions] { flex-direction: column; }
                [data-review-monitor-actions] .btn { width: 100%; }
            }
        </style>
    @endpush

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const monitor = document.querySelector('[data-review-monitor]');

                document.querySelectorAll('[data-review-decision-form]').forEach((form) => {
                    form.addEventListener('submit', (event) => {
                        if (!form.checkValidity()) {
                            return;
                        }

                        const confirmation = event.submitter?.dataset.reviewConfirm || form.dataset.reviewConfirm;
                        if (confirmation && !window.confirm(confirmation)) {
                            event.preventDefault();
                            return;
                        }

                        const button = event.submitter || form.querySelector('button:not([type]), button[type="submit"], input[type="submit"]');
                        if (!button || button.disabled) {
                            return;
                        }

                        const pendingLabel = button.dataset.reviewPendingLabel || 'Enregistrement…';
                        button.disabled = true;
                        button.setAttribute('aria-busy', 'true');
                        button.innerHTML = `<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>${pendingLabel}`;
                    });
                });

                if (monitor) {
                    monitor.focus({ preventScroll: false });

                    if (monitor.dataset.reviewMonitorTerminal !== '1') {
                        const statusUrl = monitor.dataset.reviewMonitorStatusUrl;
                        const title = monitor.querySelector('[data-review-monitor-title]');
                        const message = monitor.querySelector('[data-review-monitor-message]');
                        const detail = monitor.querySelector('[data-review-monitor-detail]');
                        const icon = monitor.querySelector('[data-review-monitor-icon]');
                        const facts = monitor.querySelector('[data-review-monitor-facts]');
                        const startedAt = Date.now();
                        const pollIntervalMs = 2_000;
                        const timeoutMs = 120_000;
                        const levels = ['primary', 'success', 'warning', 'danger', 'secondary'];

                        const updateMonitor = (outcome) => {
                            if (!outcome || Number(outcome.id) !== Number(monitor.dataset.reviewMonitor)) {
                                return false;
                            }

                            const level = levels.includes(outcome.level) ? outcome.level : 'secondary';
                            levels.forEach((candidate) => monitor.classList.remove(`alert-${candidate}`));
                            monitor.classList.add(`alert-${level}`);
                            monitor.dataset.reviewMonitorTerminal = outcome.terminal ? '1' : '0';
                            title.textContent = typeof outcome.title === 'string' ? outcome.title : 'Statut de la relance';
                            message.textContent = typeof outcome.message === 'string' ? outcome.message : 'Le statut de cette relance ne peut pas être affiché.';
                            if (facts && outcome.terminal && outcome.level === 'success') {
                                const returned = Number.isInteger(outcome.provider_result_count) ? `${outcome.provider_result_count} adresse${outcome.provider_result_count === 1 ? '' : 's'} retournée${outcome.provider_result_count === 1 ? '' : 's'}` : null;
                                const units = outcome.recorded_units === null || outcome.recorded_units === undefined ? null : `${outcome.recorded_units} unité${Number(outcome.recorded_units) === 1 ? '' : 's'} enregistrée${Number(outcome.recorded_units) === 1 ? '' : 's'} par Fretiq`;
                                const imported = Number.isInteger(outcome.imported_contacts_count) ? `${outcome.imported_contacts_count} contact${outcome.imported_contacts_count === 1 ? '' : 's'} importé${outcome.imported_contacts_count === 1 ? '' : 's'} sans envoi` : null;
                                facts.textContent = [returned, units, imported].filter(Boolean).join(' · ');
                            }

                            icon.classList.remove('bi-arrow-repeat', 'bi-check-circle', 'bi-exclamation-triangle');
                            icon.classList.add(outcome.terminal
                                ? (level === 'success' ? 'bi-check-circle' : 'bi-exclamation-triangle')
                                : 'bi-arrow-repeat');

                            const showDetail = outcome.terminal && ['review', 'failed'].includes(outcome.status);
                            detail.classList.toggle('d-none', !showDetail);
                            detail.classList.toggle('d-inline-block', showDetail);
                            if (showDetail && typeof outcome.review_url === 'string') {
                                const reviewUrl = new URL(outcome.review_url, window.location.origin);
                                if (reviewUrl.origin === window.location.origin) {
                                    detail.href = reviewUrl.href;
                                }
                            }

                            return outcome.terminal === true;
                        };

                        const poll = async () => {
                            let terminal = false;

                            try {
                                const response = await fetch(statusUrl, {
                                    headers: { Accept: 'application/json' },
                                    credentials: 'same-origin',
                                });
                                if (response.ok) {
                                    const outcome = await response.json();
                                    terminal = updateMonitor(outcome);
                                    if (terminal && !monitor.dataset.reviewMonitorReloaded && typeof outcome.review_url === 'string') {
                                        try {
                                            const reviewUrl = new URL(outcome.review_url, window.location.origin);
                                            if (reviewUrl.origin === window.location.origin) {
                                                monitor.dataset.reviewMonitorReloaded = '1';
                                                window.location.replace(reviewUrl.href);
                                            }
                                        } catch (error) {}
                                    }
                                }
                            } catch (error) {
                                terminal = false;
                            }

                            if (terminal) {
                                return;
                            }
                            if (Date.now() - startedAt >= timeoutMs) {
                                title.textContent = 'Relance en cours';
                                message.textContent = monitor.dataset.reviewMonitorTimeoutMessage;

                                return;
                            }

                            window.setTimeout(poll, pollIntervalMs);
                        };

                        if (statusUrl) {
                            window.setTimeout(poll, pollIntervalMs);
                        }
                    }
                } @if(session('success') || session('warning') || session('error')) else {
                    document.getElementById('review-queue')?.focus({ preventScroll: false });
                } @endif
            });
        </script>
    @endpush
</x-default-layout>
