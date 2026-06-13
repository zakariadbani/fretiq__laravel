{{--
    ProspectCriteria — Dernière découverte panel.

    Variables: $model (ProspectCriteria)

    Data attributes on #discovery-status-panel drive the JS polling loop defined
    in view.blade.php @push('scripts'):
        data-status         Current run status (or empty string when no run).
        data-status-url     Polling endpoint: admin.prospect_criteria.discovery_status
        data-companies-route  admin.companies.index (base URL for the CTA link)
        data-criteria-id    Criteria primary key

    The Schema::hasTable guard prevents fatals when the discovery_runs migration
    has not yet been executed.
--}}

@php
    use Illuminate\Support\Facades\Schema;

    $run = (Schema::hasTable('discovery_runs')) ? $model->latestDiscoveryRun : null;

    // Resolve label + color from the config map; fall back gracefully.
    if ($run) {
        $statusCfg   = config('global.data.discovery_run_statuses.' . $run->status, []);
        $statusLabel = $statusCfg['label'] ?? $run->status;
        $statusColor = $statusCfg['color'] ?? 'secondary';
    } else {
        $statusLabel = 'Jamais lancée';
        $statusColor = 'secondary';
    }

    // CTA N: all-time attributed total (companies with this criteria_id).
    // NEVER the run's companies_count — those are throughput counters for a
    // single execution, not the total attributed to the criterion.
    $companiesTotal = $model->companies()->count();

    // Worker-down warning: show without d-none when the run is stale.
    $workerWarnClass = ($run && $run->isStale()) ? '' : ' d-none';
@endphp

<div class="card mb-6">
    <div class="card-header">
        <div class="card-title fs-5 fw-bold">
            <i class="bi bi-arrow-repeat me-2 text-primary"></i>
            Dernière découverte
        </div>
    </div>

    <div class="card-body">
        <div
            id="discovery-status-panel"
            data-status="{{ $run?->status ?? '' }}"
            data-status-url="{{ route('admin.prospect_criteria.discovery_status', $model->id) }}"
            data-companies-route="{{ route('admin.companies.index') }}"
            data-criteria-id="{{ $model->id }}"
        >

            @if($run === null)
                {{-- Empty state: no run has ever been launched --}}
                <div class="d-flex align-items-center gap-3 mb-4">
                    <span id="discovery-status-badge" class="badge badge-light-secondary">
                        Jamais lancée
                    </span>
                </div>
                <p class="text-muted fs-7 mb-0">
                    Données disponibles après la première découverte.
                </p>
            @else
                {{-- Run exists: show status + throughput + timestamp --}}
                <div class="d-flex align-items-center flex-wrap gap-3 mb-5">
                    <span id="discovery-status-badge" class="badge badge-light-{{ $statusColor }}">
                        {{ $statusLabel }}
                    </span>
                </div>

                <div class="fs-7 text-gray-700 mb-2">
                    Cette exécution :
                    <strong><span id="discovery-run-companies">{{ $run->companies_count ?? 0 }}</span></strong>
                    entreprises traitées
                    &middot;
                    <strong><span id="discovery-run-contacts">{{ $run->contacts_count ?? 0 }}</span></strong>
                    contacts
                    &middot;
                    <strong><span id="discovery-run-lowscore">{{ $run->low_score_count ?? 0 }}</span></strong>
                    sous le seuil de score
                </div>

                <div class="fs-7 text-muted mb-4">
                    Dernière fin :
                    <span id="discovery-finished-at">
                        {{ optional($run->finished_at)->format('d/m/Y H:i') ?? '—' }}
                    </span>
                </div>
            @endif

            @if($run === null)
                {{-- Keep the IDs in the DOM even in empty state so the JS can update them --}}
                <span id="discovery-run-companies" class="d-none">0</span>
                <span id="discovery-run-contacts" class="d-none">0</span>
                <span id="discovery-run-lowscore" class="d-none">0</span>
                <span id="discovery-finished-at" class="d-none">—</span>
            @endif

            {{-- CTA: links to the companies listing filtered by this criterion.
                 N = companies()->count() (attributed total), not run throughput. --}}
            @can('view companies')
                <a
                    id="discovery-view-companies"
                    href="{{ route('admin.companies.index') }}?criteria_id={{ $model->id }}"
                    class="btn btn-sm btn-light-primary"
                >
                    Voir les
                    <span id="discovery-companies-total">{{ $companiesTotal }}</span>
                    entreprises
                </a>
            @endcan

            {{-- Worker-down warning — visible when run is stale (in-flight but no
                 progress since >60s), hidden otherwise. JS polling adds/removes
                 d-none based on the `stale` field from the status endpoint. --}}
            <div id="discovery-worker-warning" class="alert alert-warning mt-3{{ $workerWarnClass }}">
                &#9888; Le worker de file d'attente est peut-être arrêté &mdash; lancez
                <code>php artisan queue:work</code>.
            </div>

        </div>{{-- #discovery-status-panel --}}
    </div>
</div>
