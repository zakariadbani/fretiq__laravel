{{-- Permanent discovery-progress banner shared by the detail and edit pages. --}}
@php
    use App\Services\Discovery\DiscoveryProgressPresenter;
    use Illuminate\Support\Facades\Schema;

    $run = Schema::hasTable('discovery_runs') ? $model->latestDiscoveryRun : null;
    $progress = app(DiscoveryProgressPresenter::class)->present(
        $model,
        $run,
        $model->companies()->count(),
        config('services.serpapi.driver', 'local') === 'local',
    );
    $statusCfg = config('global.data.discovery_run_statuses.' . ($progress['status'] ?? ''), []);
    $statusLabel = $statusCfg['label'] ?? ($run ? $run->status : 'Jamais lancée');
    $statusColor = $statusCfg['color'] ?? 'secondary';
    $statusUrl = route('admin.prospect_criteria.discovery_status', array_filter([
        $model->id,
        'run_id' => $progress['run_id'],
    ], static fn ($value) => $value !== null));
    $context = $discoveryContext ?? 'view';
    $isActive = in_array($progress['status'], ['pending', 'running'], true);
    $isCompact = $progress['status'] === 'completed';
    $discoveryTimezone = config('app.timezone', 'UTC');
    $renderedProgress = $progress['status'] === 'completed' ? 100 : (int) ($progress['progress_percent'] ?? 0);
    $progressIndeterminate = $isActive && $progress['progress_percent'] === null;
    $heartbeatAt = $run?->updated_at?->copy()->timezone($discoveryTimezone)->format('d/m/Y H:i:s');
@endphp

<div class="card mb-6 border border-dashed {{ $isCompact ? 'border-gray-300' : 'border-primary' }}"
     data-discovery-tracker
     data-discovery-context="{{ $context }}"
     data-criteria-id="{{ (int) $model->id }}"
     data-run-id="{{ $progress['run_id'] ?? '' }}"
     data-status="{{ $progress['status'] ?? '' }}"
     data-status-url="{{ $statusUrl }}"
     data-companies-route="{{ route('admin.companies.index') }}">
    <div class="card-body py-3{{ $isCompact ? '' : ' d-none' }}" data-discovery-summary>
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex flex-wrap align-items-center gap-3">
                <span class="fw-semibold text-gray-700">
                    <i class="bi bi-check-circle text-success me-2"></i>Dernière découverte
                </span>
                <span class="badge badge-light-{{ $statusColor }}">{{ $statusLabel }}</span>
                <span class="text-muted fs-8">
                    {{ number_format($progress['companies_count']) }} entreprise(s) enregistrée(s)
                    · {{ number_format($progress['successful_enrichments']) }}/{{ number_format($progress['successful_enrichments_target']) }} enrichissement(s) réussi(s)
                    · {{ number_format($progress['contacts_count']) }} contact(s) créé(s)
                </span>
            </div>

            @can('view companies')
                <a href="{{ route('admin.companies.index') }}?criteria_id={{ (int) $model->id }}"
                   class="btn btn-sm btn-light-primary flex-shrink-0">
                    Voir les <span>{{ $progress['companies_total'] }}</span> entreprises
                </a>
            @endcan
        </div>
    </div>

    <div class="card-body py-5{{ $isCompact ? ' d-none' : '' }}" data-discovery-active-panel>
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-4">
            <div class="flex-grow-1 min-w-250px">
                <div class="d-flex align-items-center flex-wrap gap-3 mb-3">
                    <span class="fw-bold text-gray-900">
                        <i class="bi bi-arrow-repeat text-primary me-2"></i>Progression de la découverte
                    </span>
                    <span class="badge badge-light-{{ $statusColor }}" data-discovery-status-badge>{{ $statusLabel }}</span>
                    <span class="text-muted fs-8" data-discovery-phase aria-live="polite">
                        {{ $isActive ? 'Travail en cours…' : ($run ? 'Dernière exécution' : 'Prête à être lancée') }}
                    </span>
                </div>

                <div class="progress h-8px mb-4 bg-light-primary">
                    <div class="progress-bar bg-primary{{ $progressIndeterminate ? ' progress-bar-striped progress-bar-animated' : '' }}"
                         data-discovery-progress
                         role="progressbar"
                         aria-label="Progression globale"
                         style="width: {{ $progressIndeterminate ? 100 : $renderedProgress }}%"
                         @unless($progressIndeterminate) aria-valuenow="{{ $renderedProgress }}" @endunless
                         aria-valuemin="0" aria-valuemax="100"></div>
                </div>

                <div class="row g-3">
                    <div class="col-xl-3 col-md-6">
                        <div class="rounded bg-light-info p-3 h-100">
                            <div class="text-muted fs-8 text-uppercase fw-semibold">Recherches d’entreprises</div>
                            <div class="fw-bold text-gray-800">
                                <span data-discovery-searches>{{ $progress['searches_consumed'] }}</span>
                                / <span data-discovery-searches-total>{{ $progress['searches_reserved'] }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="rounded bg-light-primary p-3 h-100">
                            <div class="text-muted fs-8 text-uppercase fw-semibold">Domaines exploitables</div>
                            <div class="fw-bold text-gray-800">
                                <span data-discovery-domains>{{ $progress['candidates_total'] }}</span>
                                <span class="text-muted fs-8{{ $progress['candidates_total_final'] ? ' d-none' : '' }}" data-discovery-total-growing>(total en cours)</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="rounded bg-light-success p-3 h-100">
                            <div class="text-muted fs-8 text-uppercase fw-semibold">Domaines analysés par l’IA</div>
                            <div class="fw-bold text-gray-800">
                                <span data-discovery-candidates>{{ $progress['candidates_processed'] }}</span>
                                / <span data-discovery-candidates-total>{{ $progress['candidates_total'] }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="rounded bg-light-warning p-3 h-100">
                            <div class="text-muted fs-8 text-uppercase fw-semibold mb-1">Tentatives d’enrichissement</div>
                            <div class="fw-bold text-gray-800 mb-1">
                                Réussites — objectif :
                                <span data-discovery-successes>{{ $progress['successful_enrichments'] }}</span>
                                / <span data-discovery-successes-target>{{ $progress['successful_enrichments_target'] }}</span>
                            </div>
                            <div class="fw-bold text-gray-800">
                                Tentatives consommées — quota :
                                <span data-discovery-contact-attempts>{{ $progress['contact_attempts_consumed'] }}</span>
                                / <span data-discovery-contact-attempts-total>{{ $progress['contact_attempts_reserved'] }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="text-muted fs-8 mt-3">
                    Résultats de cette exécution :
                    <strong data-discovery-companies>{{ $progress['companies_count'] }}</strong> entreprise(s) enregistrée(s),
                    <strong data-discovery-low-score>{{ $progress['low_score_count'] }}</strong> sous le seuil d’enrichissement,
                    <strong data-discovery-contacts>{{ $progress['contacts_count'] }}</strong> contacts créé(s),
                    <strong data-discovery-excluded>{{ $progress['excluded_count'] }}</strong> exclue(s),
                    <strong data-discovery-skipped>{{ $progress['skipped_count'] }}</strong> ignorée(s).
                    <span class="ms-2" data-discovery-heartbeat>
                        @if($heartbeatAt)Dernière activité ({{ $discoveryTimezone }}) : {{ $heartbeatAt }}@endif
                    </span>
                </div>

                <div class="alert alert-danger mt-3 mb-0{{ $progress['error'] ? '' : ' d-none' }}" data-discovery-error role="alert">
                    {{ $progress['error'] ?? '' }}
                </div>
                <div class="alert alert-warning mt-3 mb-0{{ $progress['stale'] ? '' : ' d-none' }}" data-discovery-worker-warning>
                    Le worker de file d’attente ne répond peut-être plus. Vérifiez <code>php artisan queue:work</code>.
                </div>
            </div>

            @can('view companies')
                <a href="{{ route('admin.companies.index') }}?criteria_id={{ (int) $model->id }}"
                   class="btn btn-sm btn-light-primary flex-shrink-0">
                    Voir les <span data-discovery-companies-total>{{ $progress['companies_total'] }}</span> entreprises
                </a>
            @endcan
        </div>
    </div>
</div>
