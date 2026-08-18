{{--
    Per-query results — discovered companies grouped by the SerpAPI query that
    found them (§6). Each query row shows registered/non-excluded/excluded counts,
    expandable to the company rows. The audit toggle (?audit=1) reveals rejected
    (AI-excluded competitor) companies via Company::withRejected().

    Expects:
        $model       — ProspectCriteria
        $queryGroups — list<array{query: ?string, found: int, kept: int, excluded: int, companies: Collection}>
        $auditMode   — bool, current ?audit= state

    Permissions:
        @can('view companies') — gates the whole card
--}}

@can('view companies')

@php
    $totalFound    = collect($queryGroups)->sum('found');
    $totalKept     = collect($queryGroups)->sum('kept');
    $totalExcluded = collect($queryGroups)->sum('excluded');
@endphp

<div class="card mb-5">
    <div class="card-header border-0 pt-5">
        <div>
            <h3 class="card-title fw-bolder m-0">
                <i class="bi bi-diagram-3 text-info fs-3 me-2"></i>
                Résultats par requête de découverte
            </h3>
            <div class="text-muted fs-7 mt-2">
                {{ number_format($totalFound) }} entreprise(s) enregistrée(s) · {{ number_format($totalKept) }} non exclue(s) · {{ number_format($totalExcluded) }} exclue(s) par l'IA.
            </div>
            <div class="text-muted fs-8 mt-1">
                Les résultats bruts de découverte ne sont pas affichés : seuls les domaines exploitables enregistrés après normalisation apparaissent ici.
            </div>
        </div>
        <div class="card-toolbar">
            @if($auditMode)
                <a href="{{ route('admin.prospect_criteria.view', $model->id) }}#criteria_resultats"
                   class="btn btn-sm btn-light-danger">
                    <i class="bi bi-eye-slash me-1"></i>
                    Masquer les entreprises exclues
                </a>
            @else
                <a href="{{ route('admin.prospect_criteria.view', $model->id) }}?audit=1#criteria_resultats"
                   class="btn btn-sm btn-light-warning">
                    <i class="bi bi-shield-exclamation me-1"></i>
                    Auditer les exclusions IA
                </a>
            @endif
        </div>
    </div>
    <div class="card-body border-top p-9">

        @if(empty($queryGroups))
            <div class="text-muted fs-7">
                <i class="bi bi-info-circle me-1"></i>
                Aucun résultat de découverte pour l'instant.
            </div>
        @else
            @foreach($queryGroups as $i => $group)
                @php
                    $groupId = 'query_group_' . $i;
                @endphp
                <div class="border border-gray-300 rounded p-4 mb-3">
                    <div class="d-flex align-items-center justify-content-between flex-wrap">
                        <div class="d-flex align-items-center mb-2 mb-md-0">
                            <span class="badge badge-light-warning me-3">{{ $i + 1 }}</span>
                            @if($group['query'])
                                <code class="fs-7">{{ $group['query'] }}</code>
                            @else
                                <span class="text-muted fs-7 fst-italic">(sans requête associée)</span>
                            @endif
                        </div>
                        <div class="d-flex align-items-center">
                            <span class="badge badge-light-primary me-2">{{ $group['found'] }} enregistrée(s)</span>
                            <span class="badge badge-light-success me-2">{{ $group['kept'] }} non exclue(s)</span>
                            @if($group['excluded'] > 0)
                                <span class="badge badge-light-danger me-3">{{ $group['excluded'] }} exclue(s)</span>
                            @endif
                            <button type="button"
                                    class="btn btn-sm btn-light"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#{{ $groupId }}"
                                    aria-expanded="false"
                                    aria-controls="{{ $groupId }}"
                                    title="Afficher les entreprises de cette requête">
                                <i class="bi bi-chevron-down fs-7 me-1"></i>
                                Détails
                            </button>
                        </div>
                    </div>

                    <div class="collapse mt-3" id="{{ $groupId }}">
                        <div class="table-responsive">
                            <table class="table table-sm table-row-dashed table-row-gray-200 align-middle gs-0 mb-0">
                                <thead>
                                    <tr class="fw-semibold text-muted fs-7">
                                        <th class="min-w-160px">Entreprise</th>
                                        <th class="min-w-80px">Pays</th>
                                        <th class="min-w-60px">Score</th>
                                        <th>Statut</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($group['companies'] as $company)
                                        @php
                                            $isRejected = $company->qualification_status === 'rejected';
                                        @endphp
                                        @if(!$isRejected || $auditMode)
                                            <tr class="{{ $isRejected ? 'opacity-50' : '' }}">
                                                <td>
                                                    <a href="{{ route('admin.companies.view', $company->id) }}"
                                                       class="text-gray-800 fw-semibold text-hover-primary fs-7 {{ $isRejected ? 'text-decoration-line-through' : '' }}">
                                                        {{ $company->name }}
                                                    </a>
                                                    @if($company->domain)
                                                        <span class="text-muted d-block fs-8">{{ $company->domain }}</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    <span class="fs-7">{{ $company->country ?: '—' }}</span>
                                                </td>
                                                <td>
                                                    @if($company->ai_score !== null)
                                                        <span class="badge badge-light-primary">{{ $company->ai_score }}</span>
                                                    @else
                                                        <span class="text-muted">—</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    @if($isRejected)
                                                        <span class="badge badge-light-danger" title="{{ $company->ai_explanation }}">Exclue (IA)</span>
                                                    @elseif($company->enrichment_status === 'skipped_low_score')
                                                        <span class="badge badge-light-warning">Sous le seuil de contacts</span>
                                                    @else
                                                        <span class="badge badge-light-success">Non exclue</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endif
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endforeach
        @endif

    </div>
</div>

@endcan
