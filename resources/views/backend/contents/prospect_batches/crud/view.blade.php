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

@include('backend.partials.crud._tabbar', [
    'model' => $model,
    'currentPage' => 'view',
    'config' => $viewConfig,
    'actions' => '',
])

<div class="tab-content">
    <div class="tab-pane fade show active" id="prospect_batch_apercu" role="tabpanel">
        @include('backend.partials.crud._apercu', ['model' => $model, 'config' => $viewConfig])

        @if($model->status === 'review')
            <div class="alert alert-warning d-flex align-items-center mt-6">
                <i class="bi bi-exclamation-triangle fs-2 me-3"></i>
                <div class="flex-grow-1">Certaines entreprises ou certains domaines demandent votre choix.</div>
                @if(Route::has('admin.prospect_review.index'))
                    <a href="{{ route('admin.prospect_review.index', ['tab' => 'companies', 'batch' => $model->id]) }}" class="btn btn-sm btn-warning">Ouvrir À revoir</a>
                @endif
            </div>
        @elseif($model->status === 'failed')
            <div class="alert alert-danger mt-6">Le traitement n’a pas pu se terminer. Aucun détail fournisseur sensible n’est affiché ici.</div>
        @endif

        @php
            $discoverCursor = is_array($model->source_cursor) ? $model->source_cursor : [];
            $discoverInterrupted = $model->source_type === 'discover'
                && $model->cost_confirmed_at !== null
                && ! in_array($model->status, ['queued', 'running'], true)
                && ($discoverCursor['exhausted'] ?? false) !== true;
            $discoverResults = is_int($discoverCursor['results'] ?? null) ? $discoverCursor['results'] : null;
            $discoverRemaining = $discoverResults !== null ? max(0, $discoverResults - (int) ($discoverCursor['offset'] ?? 0)) : null;
        @endphp
        @if($discoverInterrupted)
            <div
                class="alert alert-warning d-flex align-items-center mt-6"
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

        @if($outcomeBreakdown->isNotEmpty())
            <div class="card mt-6">
                <div class="card-header border-0"><h3 class="card-title">Résultat de l’enrichissement</h3></div>
                <div class="card-body pt-0">
                    <div class="d-flex flex-wrap gap-3">
                        @foreach($outcomeBreakdown as $outcome)
                            <span class="badge badge-light-{{ $outcome['color'] }} fs-7 py-2 px-3">{{ $outcome['total'] }} {{ $outcome['label'] }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        <div class="card mt-6">
            <div class="card-header border-0"><h3 class="card-title">Entreprises du lot</h3></div>
            <div class="card-body pt-0">
                @if($items->isEmpty())
                    <div class="text-center py-10">
                        <i class="bi bi-building-slash fs-3x text-muted"></i>
                        <p class="text-muted mt-4 mb-0">Aucune entreprise dans ce lot.</p>
                    </div>
                @else
                    @php
                        $itemStatuses = config('global.data.prospect_batch_item_statuses', []);
                        $canOpenDecisions = Route::has('admin.prospect_review.index') && (auth()->user()?->can('review prospect matches') ?? false);
                        $itemDecisionUrl = static fn ($item) => route('admin.prospect_review.index', [
                            'tab' => 'companies',
                            'batch' => $model->id,
                            'item' => $item->id,
                            'state' => $item->status === 'failed' ? 'blocked' : 'attention',
                        ]);
                    @endphp
                    <div class="table-responsive d-none d-md-block">
                        <table class="table table-row-dashed align-middle">
                            <thead><tr><th>Entreprise</th><th>Domaine</th><th>Statut</th><th>Contacts importés</th><th>Motif</th></tr></thead>
                            <tbody>
                            @foreach($items as $item)
                                @php $itemIsActionable = $canOpenDecisions && in_array($item->status, ['review', 'failed'], true); @endphp
                                <tr>
                                    <td>
                                        @if($itemIsActionable)
                                            <a href="{{ $itemDecisionUrl($item) }}" class="fw-semibold text-gray-900 text-hover-primary">{{ $item->company_name }}</a>
                                        @else
                                            <div class="fw-semibold">{{ $item->company_name }}</div>
                                        @endif
                                        <div class="text-muted fs-8">{{ collect([$item->city, $item->country])->filter()->join(', ') }}</div>
                                    </td>
                                    <td>{{ $item->selected_domain ?: $item->provided_domain ?: '—' }}</td>
                                    <td><span class="badge badge-light-{{ $itemStatuses[$item->status]['color'] ?? 'secondary' }}">{{ $itemStatuses[$item->status]['label'] ?? $item->status }}</span></td>
                                    <td>{{ $item->imported_contacts_count }}</td>
                                    <td>{{ $presenter->itemIssue($item)['label'] }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="d-md-none vstack gap-3">
                        @foreach($items as $item)
                            @php $itemIsActionable = $canOpenDecisions && in_array($item->status, ['review', 'failed'], true); @endphp
                            <article class="card border">
                                <div class="card-body py-4">
                                    <div class="d-flex justify-content-between gap-3">
                                        @if($itemIsActionable)
                                            <a href="{{ $itemDecisionUrl($item) }}" class="text-gray-900 text-hover-primary fw-bold">{{ $item->company_name }}</a>
                                        @else
                                            <strong>{{ $item->company_name }}</strong>
                                        @endif
                                        <span class="badge badge-light-{{ $itemStatuses[$item->status]['color'] ?? 'secondary' }}">{{ $itemStatuses[$item->status]['label'] ?? $item->status }}</span>
                                    </div>
                                    <div class="text-muted fs-7 mt-2">{{ $item->selected_domain ?: $item->provided_domain ?: 'Domaine à revoir' }}</div>
                                    <div class="fs-8 mt-2">{{ $item->imported_contacts_count }} contact(s) importé(s)</div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                    <p class="text-muted fs-8 mt-4 mb-3">Importer un contact ne déclenche aucun email. Les règles d’éligibilité restent appliquées au moment de préparer un envoi.</p>
                    <div class="mt-5">{{ $items->links('pagination::bootstrap-5') }}</div>
                @endif
            </div>
        </div>
    </div>
</div>

@php
    $liveStatusPercent = $model->total_items > 0 ? (int) floor(($model->processed_items / $model->total_items) * 100) : 0;
@endphp
@if(in_array($model->status, ['queued', 'running'], true))
    <div id="prospect-batch-live-status" class="alert alert-primary d-flex align-items-center mt-6" data-status-url="{{ route('admin.prospect_batches.status', $model) }}" aria-live="polite">
        <i class="bi bi-arrow-repeat fs-2 me-3" aria-hidden="true"></i>
        <div class="flex-grow-1">
            <div class="fw-bold">Traitement en cours</div>
            <div class="progress h-8px my-3"><div class="progress-bar bg-primary" data-live-status-bar style="width: {{ $liveStatusPercent }}%"></div></div>
            <div class="fs-8 text-muted" data-live-status-progress>{{ $liveStatusPercent }} % — {{ $model->processed_items }}/{{ $model->total_items }}</div>
            <div class="alert alert-warning d-none mt-3 mb-0 py-2 fs-8" data-live-status-waiting>Le lot attend un worker. Vérifiez que la file « default » est démarrée.</div>
        </div>
    </div>
@endif

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-tabs.js') }}"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
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
                            window.location.reload();
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
                        window.location.reload();
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
