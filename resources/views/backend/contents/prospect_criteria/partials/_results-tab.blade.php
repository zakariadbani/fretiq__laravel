{{--
    Résultats tab pane — discovered companies for a prospect_criteria, with
    per-company expandable contacts (Bootstrap collapse, no custom JS).

    Expects:
        $model           — ProspectCriteria
        $resultCompanies — LengthAwarePaginator (companies()->with('contacts'), 25/page, 'results_page')

    Permissions:
        @can('view companies')  — gates the entire data table
        @can('view contacts')   — gates the contacts sub-table
        @can('run discovery')   — gates the empty-state CTA
--}}

<div class="card">
    <div class="card-header border-0 pt-5">
        <h3 class="card-title fw-bolder m-0">
            <i class="bi bi-building-check text-primary fs-3 me-2"></i>
            Entreprises découvertes ({{ $resultCompanies->total() }})
        </h3>
        <div class="card-toolbar">
            <a href="{{ route('admin.companies.index') . '?criteria_id=' . $model->id }}"
               class="btn btn-sm btn-light-primary">
                Voir toutes les entreprises
                <i class="bi bi-arrow-right ms-1"></i>
            </a>
        </div>
    </div>

    <div class="card-body border-top p-0 mt-4">

        @can('view companies')

            @if($resultCompanies->total() === 0)

                {{-- Empty state --}}
                <div class="text-center py-10 text-muted">
                    <i class="bi bi-building fs-2x mb-3 d-block"></i>
                    Aucune entreprise découverte pour ce critère.
                    @can('run discovery')
                        <div class="mt-4">
                            <button type="button"
                                    class="btn btn-sm btn-light-success"
                                    onclick="launchDiscovery({{ $model->id }}, '{{ csrf_token() }}')">
                                <i class="bi bi-play-fill me-1"></i>
                                Lancer la découverte
                            </button>
                        </div>
                    @endcan
                </div>

            @else

                <div class="table-responsive">
                    <table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-3">
                        <thead>
                            <tr class="fw-bold text-muted bg-light">
                                <th class="ps-5 min-w-160px">Entreprise</th>
                                <th class="min-w-100px">Secteur</th>
                                <th class="min-w-80px">Pays</th>
                                <th class="min-w-80px">Taille</th>
                                <th class="min-w-60px">Score</th>
                                <th class="min-w-80px">Contacts</th>
                                <th class="text-end pe-5">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($resultCompanies as $company)
                                @php
                                    // Country label mapping — canonical CompaniesDataTable:203 pattern.
                                    $countryCode  = !empty($company->country) ? strtoupper($company->country) : null;
                                    $countryLabel = $countryCode
                                        ? (config('global.data.company_countries')[$countryCode] ?? $company->country)
                                        : null;

                                    // Qualification status badge with safe fallback (never blank).
                                    $qStatus    = $company->qualification_status ?? '';
                                    $qStatusCfg = config('global.data.company_qualification_statuses.' . $qStatus);

                                    $contactCount = $company->contacts->count();
                                @endphp

                                {{-- Company row --}}
                                <tr>
                                    <td class="ps-5">
                                        <a href="{{ route('admin.companies.view', $company->id) }}"
                                           class="text-gray-900 fw-bold text-hover-primary fs-6">
                                            {{ $company->name }}
                                        </a>
                                        @if($company->domain)
                                            <span class="text-muted fw-semibold d-block fs-7">{{ $company->domain }}</span>
                                        @endif
                                        @if($qStatusCfg)
                                            <span class="badge badge-light-{{ $qStatusCfg['color'] }} mt-1">{{ $qStatusCfg['label'] }}</span>
                                        @elseif($qStatus !== '')
                                            <span class="badge badge-light-secondary mt-1">{{ $qStatus }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($company->sector)
                                            <span class="text-gray-700 fs-7">{{ $company->sector }}</span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($countryLabel)
                                            <span class="fw-semibold">{{ $countryLabel }}</span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($company->estimated_size)
                                            <span class="text-gray-700 fs-7">{{ $company->estimated_size }}</span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($company->ai_score !== null)
                                            <span class="badge badge-light-primary">{{ $company->ai_score }}</span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        <button type="button"
                                                class="btn btn-sm btn-light d-flex align-items-center gap-1"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#criteria_contacts_{{ $company->id }}"
                                                aria-expanded="false"
                                                aria-controls="criteria_contacts_{{ $company->id }}">
                                            <span>{{ $contactCount }}</span>
                                            <i class="bi bi-chevron-down fs-7"></i>
                                        </button>
                                    </td>
                                    <td class="text-end pe-5">
                                        @can('view companies')
                                            <a href="{{ route('admin.companies.view', $company->id) }}"
                                               class="btn btn-icon btn-bg-light btn-active-color-primary btn-sm"
                                               title="Voir l'entreprise">
                                                <i class="bi bi-eye fs-4"></i>
                                            </a>
                                        @endcan
                                    </td>
                                </tr>

                                {{-- Expandable contacts row — full colspan, no padding so collapsed = invisible --}}
                                <tr>
                                    <td colspan="7" class="p-0 border-0">
                                        <div class="collapse" id="criteria_contacts_{{ $company->id }}">
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
                                                                    <th>Statut</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                @foreach($company->contacts as $contact)
                                                                    @php
                                                                        // Contact status badge with safe fallback (never blank).
                                                                        $cStatus    = $contact->status ?? '';
                                                                        $cStatusCfg = config('global.data.contact_statuses.' . $cStatus);
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

                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Pagination footer — must name the view explicitly (Laravel 11 defaults to Tailwind) --}}
                @if($resultCompanies->hasPages())
                    <div class="card-footer d-flex justify-content-end py-4">
                        {{ $resultCompanies->fragment('criteria_resultats')->withQueryString()->links('pagination::bootstrap-5') }}
                    </div>
                @endif

            @endif

        @else

            {{-- Restricted access block --}}
            <div class="text-center py-10 text-muted">
                <i class="bi bi-lock fs-2x mb-3 d-block"></i>
                Accès restreint — vous n'avez pas la permission de voir les entreprises.
            </div>

        @endcan

    </div>
</div>
