{{--
    Résultats tab pane — this batch's items, with an outcome strip + a sortable,
    fragment-paginated table (criteria's _results-tab pattern, cf.
    resources/views/backend/contents/prospect_criteria/partials/_results-tab.blade.php).

    Expects:
        $model            — ProspectBatch
        $items            — LengthAwarePaginator (batch->items()->withCount('importedContacts')
                             ->with('company.contacts' [lifecycle_state select]), 25/page, 'results_page')
        $resultsSort      — current sort key (row/name/domain/status)
        $resultsDir       — current sort direction (asc/desc)
        $outcomeBreakdown — Collection<array{label,color,total}> — companies.enrichment_status
                             grouped for this batch (ProspectBatchController::enrichmentOutcomeBreakdown()).
        $presenter        — ProspectReviewPresenter (itemIssue() / workspaceUrl())
        $stalledCount     — int, ProspectReviewWorkspace::stalledItemsCountForBatch() — `pending`
                             items an operator's own retry click orphaned (no worker consumed the
                             job). Gates the "Reprendre les lignes en attente" bar below.

    Row split: an item with a loaded $item->company gets Contacts (expandable, criteria's
    _results-tab pattern) + Actions (view/enrich) cells. Guard on $item->company !== null,
    NOT $item->company_id — Company's notRejected global scope nulls the relation for a
    since-rejected company while company_id stays set. An item without a company keeps the
    old imported_contacts_count + reason-label cells (review/failed/skipped rows never get one).

    Permissions:
        @can('review prospect matches') — gates the row link into the review pane, and the
        "Reprendre les lignes en attente" bar (same permission the drain endpoint's own
        middleware requires — never `run prospect resolution`).
        @can('view companies')  — gates the "Voir l'entreprise" action.
        @can('view contacts')   — gates the expandable contacts sub-table.
        @can('enrich companies') — gates the "Récupérer les contacts" action, further gated on
        a domain being set, the company not being rejected, and !hasSocialDomain() (Company.php:212).
--}}

@php
    $resultsSort = $resultsSort ?? 'row';
    $resultsDir  = $resultsDir ?? 'asc';

    $sortUrl = function (string $key) use ($model, $resultsSort, $resultsDir): string {
        $params = request()->except(['results_sort', 'results_dir', 'results_page']);
        $params['results_sort'] = $key;
        $params['results_dir']  = ($resultsSort === $key && $resultsDir === 'asc') ? 'desc' : 'asc';
        $query = http_build_query($params);

        return route('admin.prospect_batches.view', $model->id) . ($query !== '' ? '?' . $query : '') . '#prospect_batch_resultats';
    };

    $sortIcon = fn (string $key): string => $resultsSort === $key
        ? ($resultsDir === 'asc' ? 'bi-sort-alpha-down' : 'bi-sort-alpha-up')
        : 'bi-arrow-down-up';

    $sortClass = fn (string $key): string => $resultsSort === $key
        ? 'text-primary fw-bold text-decoration-none'
        : 'text-muted text-hover-primary text-decoration-none';

    $itemStatuses = config('global.data.prospect_batch_item_statuses', []);
    $canOpenDecisions = auth()->user()?->can('review prospect matches') ?? false;
@endphp

@if(($outcomeBreakdown ?? collect())->isNotEmpty())
    <div class="card mb-5">
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

@if($canOpenDecisions && ($stalledCount ?? 0) > 0)
    <div class="card mb-5">
        <div class="card-body p-5 p-lg-6">
            <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-4">
                <div>
                    <div class="fw-bold">Des entreprises attendent une reprise de traitement</div>
                    <p class="text-muted mb-0">Une relance a été demandée pour ces entreprises, mais aucun worker ne l’a encore traitée — elles sont restées en file.</p>
                </div>
                <button type="button" class="btn btn-warning text-nowrap"
                    onclick="launchStalledDrain(this)"
                    data-dispatch-url="{{ route('admin.prospect_review.retry_drain.store', ['batch' => $model->id, 'state' => 'stalled']) }}"
                    data-csrf-token="{{ csrf_token() }}"
                >
                    <i class="bi bi-arrow-repeat me-2" aria-hidden="true"></i>Reprendre les lignes en attente ({{ $stalledCount }})
                </button>
            </div>
        </div>
    </div>
@endif

<div class="card">
    <div class="card-header border-0 pt-5">
        <div>
            <h3 class="card-title fw-bolder m-0">
                <i class="bi bi-building-check text-primary fs-3 me-2"></i>
                Entreprises du lot ({{ $items->total() }})
            </h3>
            <div class="text-muted fs-7 mt-2">
                Cliquez sur un en-tête pour trier la table. Importer un contact ne déclenche aucun email ; l’éligibilité reste contrôlée séparément avant chaque campagne.
            </div>
        </div>
    </div>

    <div class="card-body border-top p-0 mt-4">
        @if($items->total() === 0)
            <div class="text-center py-10 text-muted">
                <i class="bi bi-building-slash fs-2x mb-3 d-block"></i>
                Aucune entreprise dans ce lot.
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-3">
                    <thead>
                        <tr class="fw-bold text-muted bg-light">
                            <th class="ps-5 min-w-60px">
                                <a href="{{ $sortUrl('row') }}" class="{{ $sortClass('row') }}">
                                    Ligne <i class="bi {{ $sortIcon('row') }} ms-1"></i>
                                </a>
                            </th>
                            <th class="min-w-160px">
                                <a href="{{ $sortUrl('name') }}" class="{{ $sortClass('name') }}">
                                    Entreprise <i class="bi {{ $sortIcon('name') }} ms-1"></i>
                                </a>
                            </th>
                            <th class="min-w-140px">
                                <a href="{{ $sortUrl('domain') }}" class="{{ $sortClass('domain') }}">
                                    Domaine <i class="bi {{ $sortIcon('domain') }} ms-1"></i>
                                </a>
                            </th>
                            <th class="min-w-100px">
                                <a href="{{ $sortUrl('status') }}" class="{{ $sortClass('status') }}">
                                    Statut <i class="bi {{ $sortIcon('status') }} ms-1"></i>
                                </a>
                            </th>
                            <th class="min-w-100px">Contacts</th>
                            <th class="text-end pe-5">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($items as $item)
                            @php
                                $itemIsActionable = $canOpenDecisions && in_array($item->status, ['review', 'failed'], true);
                                $company = $item->company;

                                if ($company !== null) {
                                    $contactCount = $company->contacts->count();
                                    $enrichmentStatus = $company->enrichment_status;
                                    // ponytail: imported contacts leave enrichment_status NULL, so the
                                    // null-fallback badge ("Recherche de contacts non effectuée") is only
                                    // true when there are zero contacts — with contacts already present,
                                    // asserting "no search happened" next to a non-zero count is self-
                                    // contradictory, so render no badge and let the count speak for itself.
                                    $enrichmentCfg = $enrichmentStatus !== null
                                        ? config('global.data.company_enrichment_statuses.' . $enrichmentStatus)
                                        : ($contactCount === 0 ? config('global.data.company_enrichment_status_null') : null);
                                }
                            @endphp
                            <tr>
                                <td class="ps-5 text-muted">{{ $item->row_number }}</td>
                                <td>
                                    @if($itemIsActionable)
                                        <a href="{{ $presenter->workspaceUrl($model->id, ['item' => $item->id, 'state' => $item->status === 'failed' ? 'blocked' : 'attention']) }}"
                                           class="text-gray-900 fw-bold text-hover-primary fs-6">
                                            {{ $item->company_name }}
                                        </a>
                                    @else
                                        <span class="fw-bold text-gray-900">{{ $item->company_name }}</span>
                                    @endif
                                    <span class="text-muted fw-semibold d-block fs-7">{{ collect([$item->city, $item->country])->filter()->join(', ') ?: '—' }}</span>
                                </td>
                                <td>
                                    <span class="text-gray-700 fs-7">{{ $item->selected_domain ?: $item->provided_domain ?: '—' }}</span>
                                </td>
                                <td>
                                    <span class="badge badge-light-{{ $itemStatuses[$item->status]['color'] ?? 'secondary' }}">{{ $itemStatuses[$item->status]['label'] ?? $item->status }}</span>
                                </td>
                                @if($company !== null)
                                    <td>
                                        <div class="d-flex flex-column align-items-start gap-1">
                                            <button type="button"
                                                    class="btn btn-sm btn-light d-flex align-items-center gap-1"
                                                    data-bs-toggle="collapse"
                                                    data-bs-target="#batch_item_contacts_{{ $item->id }}"
                                                    aria-expanded="false"
                                                    aria-controls="batch_item_contacts_{{ $item->id }}">
                                                <span>{{ $contactCount }}</span>
                                                <i class="bi bi-chevron-down fs-7"></i>
                                            </button>
                                            @if($enrichmentCfg)
                                                <span class="badge badge-light-{{ $enrichmentCfg['color'] }} fs-8">
                                                    {{ $enrichmentCfg['label'] }}
                                                </span>
                                            @elseif($enrichmentStatus !== null)
                                                <span class="badge badge-light-secondary fs-8">{{ $enrichmentStatus }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="text-end pe-5">
                                        @can('view companies')
                                            <a href="{{ route('admin.companies.view', $company->id) }}"
                                               class="btn btn-icon btn-bg-light btn-active-color-primary btn-sm me-1"
                                               title="Voir l'entreprise">
                                                <i class="bi bi-eye fs-4"></i>
                                            </a>
                                        @endcan
                                        @can('enrich companies')
                                            @if($company->domain && $company->qualification_status !== 'rejected' && ! $company->hasSocialDomain())
                                                <button type="button"
                                                        class="btn btn-icon btn-bg-light btn-active-color-success btn-sm"
                                                        onclick="enrichCompanyRow(this)"
                                                        data-company-id="{{ $company->id }}"
                                                        data-company-name="{{ $company->name }}"
                                                        data-url="{{ route('admin.companies.enrich', $company->id) }}"
                                                        data-csrf-token="{{ csrf_token() }}"
                                                        title="Récupérer les contacts">
                                                    <i class="bi bi-person-plus fs-4"></i>
                                                </button>
                                            @endif
                                        @endcan
                                    </td>
                                @else
                                    <td>{{ $item->imported_contacts_count }}</td>
                                    <td class="text-end pe-5">
                                        <span class="text-muted fs-7">{{ $presenter->itemIssue($item)['label'] }}</span>
                                    </td>
                                @endif
                            </tr>

                            @if($company !== null)
                                {{-- Expandable contacts row — full colspan, no padding so collapsed = invisible --}}
                                <tr>
                                    <td colspan="6" class="p-0 border-0">
                                        <div class="collapse" id="batch_item_contacts_{{ $item->id }}">
                                            <div class="px-5 py-4 bg-light-secondary">
                                                @can('view contacts')
                                                    @if($company->contacts->isEmpty())
                                                        <p class="text-muted mb-0 fs-7">Aucun contact.</p>
                                                    @else
                                                        <table class="table table-sm table-row-dashed table-row-gray-200 align-middle gs-0 mb-0">
                                                            <thead>
                                                                <tr class="fw-semibold text-muted fs-7">
                                                                    <th class="min-w-140px">Nom</th>
                                                                    <th class="min-w-140px">Email</th>
                                                                    <th class="min-w-100px">Poste</th>
                                                                    <th>État</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                @foreach($company->contacts as $contact)
                                                                    @php
                                                                        $cStatus    = $contact->lifecycle_state ?? 'needs_verification';
                                                                        $cStatusCfg = config('global.data.contact_lifecycle_states.' . $cStatus);
                                                                    @endphp
                                                                    <tr>
                                                                        <td>
                                                                            <a href="{{ route('admin.contacts.view', $contact->id) }}"
                                                                               class="text-gray-800 fw-semibold text-hover-primary fs-7">
                                                                                {{ $contact->name }}
                                                                            </a>
                                                                        </td>
                                                                        <td>
                                                                            @if($contact->email)
                                                                                <a href="mailto:{{ $contact->email }}"
                                                                                   class="text-gray-600 text-hover-primary fs-7">
                                                                                    {{ $contact->email }}
                                                                                </a>
                                                                            @else
                                                                                <span class="text-muted fs-7">—</span>
                                                                            @endif
                                                                        </td>
                                                                        <td>
                                                                            @if($contact->position)
                                                                                <span class="text-gray-600 fs-7">{{ $contact->position }}</span>
                                                                            @else
                                                                                <span class="text-muted fs-7">—</span>
                                                                            @endif
                                                                        </td>
                                                                        <td>
                                                                            @if($cStatusCfg)
                                                                                <span class="badge badge-light-{{ $cStatusCfg['color'] }}">{{ $cStatusCfg['label'] }}</span>
                                                                            @elseif($cStatus !== '')
                                                                                <span class="badge badge-light-secondary">{{ $cStatus }}</span>
                                                                            @else
                                                                                <span class="text-muted">—</span>
                                                                            @endif
                                                                        </td>
                                                                    </tr>
                                                                @endforeach
                                                            </tbody>
                                                        </table>
                                                    @endif
                                                @else
                                                    <p class="text-muted mb-0 fs-7">Accès restreint aux contacts.</p>
                                                @endcan
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($items->hasPages())
                <div class="card-footer d-flex justify-content-end py-4">
                    {{ $items->fragment('prospect_batch_resultats')->withQueryString()->links('pagination::bootstrap-5') }}
                </div>
            @endif
        @endif
    </div>
</div>
