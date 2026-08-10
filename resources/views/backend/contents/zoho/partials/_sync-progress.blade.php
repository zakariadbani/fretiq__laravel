@php
    $progressSummary = $progress['summary'];
    $progressBatch = $progress['batch'];
    $progressDeterminate = (bool) ($progressSummary['determinate'] ?? false);
    $progressPercent = $progressSummary['percent'] ?? null;
    $progressActive = !($progressBatch['terminal'] ?? false) && in_array($progressBatch['status'], ['queued', 'running'], true);
    $phaseLabels = [
        'waiting' => 'En attente',
        'enumerating' => 'Énumération',
        'hydrating' => 'Hydratation',
        'retrying' => 'Nouvelle tentative',
        'paused' => 'En pause',
        'completed' => 'Terminée',
        'error' => 'En erreur',
    ];
    $batchStatusLabels = [
        'queued' => 'En attente',
        'running' => 'En cours',
        'paused' => 'En pause',
        'success' => 'Terminée',
        'partial' => 'Terminée avec anomalies',
        'error' => 'En erreur',
    ];
    $formatProgressDate = static function (?string $value): string {
        if (!$value) {
            return 'aucune activité';
        }

        return \Carbon\CarbonImmutable::parse($value)->format('d/m/Y H:i:s');
    };
@endphp

<section
    class="card border border-gray-300 mb-6"
    data-zoho-live-progress
    data-batch-id="{{ $progressBatch['id'] }}"
    data-batch-status="{{ $progressBatch['status'] }}"
    data-status-url="{{ route('admin.zoho.v2.progress', $progressBatch['id']) }}"
    data-poll-after-ms="{{ $progress['poll_after_ms'] }}"
    aria-labelledby="zoho-live-progress-title"
>
    <div class="card-header align-items-center gap-3 py-4">
        <div>
            <h3 class="card-title mb-0" id="zoho-live-progress-title">Progression en direct du lot #{{ $progressBatch['id'] }}</h3>
            <div class="text-muted fs-8">
                <span data-zoho-progress-status>{{ $batchStatusLabels[$progressBatch['status']] ?? ucfirst($progressBatch['status']) }}</span>
                · Actualisé <span data-zoho-progress-observed-at>{{ $formatProgressDate($progress['observed_at']) }}</span>
            </div>
        </div>
        <span class="badge badge-light-warning {{ ($progress['stalled'] ?? false) ? '' : 'd-none' }}" data-zoho-progress-stalled>
            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Aucune progression durable récente
        </span>
    </div>

    <div class="card-body">
        <div class="row g-3 mb-5">
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="rounded border border-gray-300 h-100 p-3">
                    <div class="text-muted fs-8 text-uppercase">Lot</div>
                    <div class="fw-bold">#{{ $progressBatch['id'] }} · <span data-zoho-progress-status>{{ $batchStatusLabels[$progressBatch['status']] ?? ucfirst($progressBatch['status']) }}</span></div>
                    <div class="text-muted fs-8">Dernière activité : <span data-zoho-progress-last-activity>{{ $formatProgressDate($progress['last_activity_at']) }}</span></div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="rounded border border-gray-300 h-100 p-3">
                    <div class="text-muted fs-8 text-uppercase">Modules terminés</div>
                    <div class="fs-3 fw-bold" data-zoho-progress-modules>{{ $progressSummary['modules_completed'] }} / {{ $progressSummary['modules_total'] }}</div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="rounded border border-gray-300 h-100 p-3">
                    <div class="text-muted fs-8 text-uppercase">Identifiants découverts</div>
                    <div class="fs-3 fw-bold" data-zoho-progress-discovered>{{ number_format($progressSummary['discovered']) }}</div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="rounded border border-gray-300 h-100 p-3">
                    <div class="text-muted fs-8 text-uppercase">Fiches Traitées</div>
                    <div class="fs-3 fw-bold" data-zoho-progress-processed>{{ number_format($progressSummary['processed']) }}</div>
                    <div class="text-muted fs-8">
                        <span data-zoho-progress-queued>{{ number_format($progressSummary['queued']) }}</span> en attente ·
                        <span data-zoho-progress-processing>{{ number_format($progressSummary['processing']) }}</span> en cours ·
                        <span data-zoho-progress-quarantined>{{ number_format($progressSummary['quarantined']) }}</span> quarantainées
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2 mb-2">
            <div class="fw-semibold" data-zoho-progress-message aria-live="polite">
                {{ $progressDeterminate ? 'Fiches Traitées' : 'Énumération des identifiants — le total augmente' }}
            </div>
            <span class="text-muted fs-8" data-zoho-progress-percent>{{ $progressDeterminate ? ($progressPercent ?? 0).' %' : 'Total en cours de calcul' }}</span>
        </div>
        <div class="progress h-8px mb-5">
            <div
                class="progress-bar bg-primary {{ !$progressDeterminate ? 'progress-bar-striped' : '' }} {{ !$progressDeterminate && $progressActive ? 'progress-bar-animated' : '' }}"
                data-zoho-progress-bar
                role="progressbar"
                aria-label="Progression globale du lot"
                aria-valuemin="0"
                aria-valuemax="100"
                @if($progressDeterminate) aria-valuenow="{{ $progressPercent ?? 0 }}" @endif
                style="width: {{ $progressDeterminate ? ($progressPercent ?? 0) : 100 }}%"
            ></div>
        </div>

        <div class="d-flex flex-column gap-2" data-zoho-progress-module-list>
            @foreach($progress['modules'] as $key => $moduleProgress)
                @php
                    $moduleDeterminate = (bool) $moduleProgress['enumeration_complete'];
                    $modulePercent = $moduleProgress['percent'] ?? null;
                    $moduleActive = $progressActive && !in_array($moduleProgress['phase'], ['paused', 'completed', 'error'], true);
                @endphp
                <article
                    class="rounded border border-gray-300 p-3 {{ $moduleProgress['phase'] === 'completed' ? 'd-none' : '' }}"
                    data-zoho-progress-module="{{ $key }}"
                >
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center gap-3">
                        <div class="flex-grow-1 min-w-0">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                <span class="fw-bold" data-zoho-progress-module-label>{{ $moduleProgress['label'] }}</span>
                                <span class="badge badge-light-primary" data-zoho-progress-phase>{{ $phaseLabels[$moduleProgress['phase']] ?? ucfirst($moduleProgress['phase']) }}</span>
                                <span class="text-muted fs-8" data-zoho-progress-module-percent>{{ $moduleDeterminate ? ($modulePercent ?? 0).' %' : 'total en cours' }}</span>
                            </div>
                            <div class="text-muted fs-8">
                                <span data-zoho-progress-discovered>{{ number_format($moduleProgress['discovered']) }}</span> découverts ·
                                <span data-zoho-progress-processed>{{ number_format($moduleProgress['processed']) }}</span> traités ·
                                <span data-zoho-progress-queued>{{ number_format($moduleProgress['queued']) }}</span> en attente ·
                                <span data-zoho-progress-processing>{{ number_format($moduleProgress['processing']) }}</span> en cours ·
                                <span data-zoho-progress-quarantined>{{ number_format($moduleProgress['quarantined']) }}</span> quarantainés
                            </div>
                            <div class="text-muted fs-9">Dernière activité : <span data-zoho-progress-last-activity>{{ $formatProgressDate($moduleProgress['last_activity_at']) }}</span></div>
                        </div>
                        <div class="flex-lg-grow-1" style="min-width: min(100%, 16rem)">
                            <div class="progress h-8px">
                                <div
                                    class="progress-bar bg-primary {{ !$moduleDeterminate ? 'progress-bar-striped' : '' }} {{ !$moduleDeterminate && $moduleActive ? 'progress-bar-animated' : '' }}"
                                    data-zoho-progress-bar
                                    role="progressbar"
                                    aria-label="Progression du module {{ $moduleProgress['label'] }}"
                                    aria-valuemin="0"
                                    aria-valuemax="100"
                                    @if($moduleDeterminate) aria-valuenow="{{ $modulePercent ?? 0 }}" @endif
                                    style="width: {{ $moduleDeterminate ? ($modulePercent ?? 0) : 100 }}%"
                                ></div>
                            </div>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    </div>
</section>
