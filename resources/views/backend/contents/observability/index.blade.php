<x-default-layout>

@section('title')
    Observabilité des automatisations
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Administration'], ['label' => 'Observabilité']]" />
@endsection

@php
    $cronHealthy = $scheduler['status'] === 'healthy';
    $cronLabel = match ($scheduler['status']) {
        'healthy' => 'Cron actif',
        'stale' => 'Cron en retard',
        default => 'Cron non détecté',
    };
    $cronColor = $cronHealthy ? 'success' : 'danger';
    $jobStates = [
        'waiting' => ['label' => 'En attente', 'color' => 'primary'],
        'delayed' => ['label' => 'Programmé', 'color' => 'warning'],
        'reserved' => ['label' => 'Réservé / en cours', 'color' => 'success'],
    ];
@endphp

@if (session('success'))
    <div class="alert alert-success d-flex align-items-center mb-6" role="status">
        <i class="bi bi-check-circle fs-2 me-3"></i>{{ session('success') }}
    </div>
@endif
@if (session('error'))
    <div class="alert alert-danger d-flex align-items-center mb-6" role="alert">
        <i class="bi bi-exclamation-triangle fs-2 me-3"></i>{{ session('error') }}
    </div>
@endif

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-6">
    <div class="text-muted fs-7">
        Actualisé le {{ $refreshedAt->format('d/m/Y à H:i:s') }} · heure de Paris
    </div>
    <a href="{{ route('admin.observability.index') }}" class="btn btn-sm btn-light-primary">
        <i class="bi bi-arrow-clockwise me-1"></i> Actualiser
    </a>
</div>

<div class="row g-5 g-xl-8 mb-8">
    <div class="col-xl-3 col-md-6">
        <div class="card">
            <div class="card-body">
                <i class="bi bi-clock-history fs-2x text-{{ $cronColor }}"></i>
                <div class="text-gray-900 fw-bold fs-2 mb-2 mt-5">{{ $cronLabel }}</div>
                <div class="fw-semibold text-gray-600">Planificateur Laravel</div>
                <div class="mt-2">
                    @if ($scheduler['last_tick_at'])
                        <span class="badge badge-light-{{ $cronColor }} fs-8">Dernier passage {{ $scheduler['last_tick_at']->diffForHumans() }}</span>
                    @else
                        <span class="badge badge-light-danger fs-8">Aucun heartbeat reçu</span>
                    @endif
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card">
            <div class="card-body">
                <i class="bi bi-hourglass-split fs-2x text-primary"></i>
                <div class="text-gray-900 fw-bold fs-2 mb-2 mt-5">{{ number_format($counts['waiting_jobs']) }}</div>
                <div class="fw-semibold text-gray-600">Jobs en attente / programmés</div>
                <div class="mt-2"><span class="badge badge-light-primary fs-8">Table jobs</span></div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card">
            <div class="card-body">
                <i class="bi bi-cpu fs-2x text-success"></i>
                <div class="text-gray-900 fw-bold fs-2 mb-2 mt-5">{{ number_format($counts['reserved_jobs']) }}</div>
                <div class="fw-semibold text-gray-600">Jobs réservés / en cours</div>
                <div class="mt-2"><span class="badge badge-light-success fs-8">Pris par un worker</span></div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card">
            <div class="card-body">
                <i class="bi bi-x-circle fs-2x {{ $counts['failed_jobs'] > 0 ? 'text-danger' : 'text-success' }}"></i>
                <div class="text-gray-900 fw-bold fs-2 mb-2 mt-5">{{ number_format($counts['failed_jobs']) }}</div>
                <div class="fw-semibold text-gray-600">Jobs échoués</div>
                <div class="mt-2"><span class="badge badge-light-{{ $counts['failed_jobs'] > 0 ? 'danger' : 'success' }} fs-8">{{ $counts['failed_jobs'] > 0 ? 'Action requise' : 'Aucun échec' }}</span></div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-8" id="active-queue">
    <div class="card-header border-0 pt-5">
        <h3 class="card-title align-items-start flex-column">
            <span class="card-label fw-bold text-gray-900">File active</span>
            <span class="text-muted mt-1 fw-semibold fs-7">50 jobs les plus récents de la file Laravel</span>
        </h3>
    </div>
    <div class="card-body py-3">
        @if (config('queue.default') !== 'database')
            <div class="alert alert-warning mb-0">La liste détaillée est disponible uniquement avec le driver <code>database</code>.</div>
        @elseif ($activeJobs->isEmpty())
            <div class="text-center text-muted py-10">
                <i class="bi bi-inbox fs-3x text-primary mb-3 d-block"></i>
                <div class="fw-semibold fs-6">File vide</div>
                <div class="fs-7 mt-1">Cela ne confirme pas qu’un worker inactif est démarré.</div>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-4">
                    <thead><tr class="fw-bold text-muted">
                        <th>Job</th><th>File</th><th>État</th><th>Tentatives</th><th>Créé</th><th>Exécution prévue</th><th class="text-end">Action</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($activeJobs as $job)
                        @php($state = $jobStates[$job->state])
                        <tr>
                            <td><span class="fw-bold text-gray-900">{{ $job->name }}</span><span class="text-muted d-block fs-8">#{{ $job->id }}</span></td>
                            <td><span class="badge badge-light-primary">{{ $job->queue }}</span></td>
                            <td><span class="badge badge-light-{{ $state['color'] }}">{{ $state['label'] }}</span></td>
                            <td>{{ $job->attempts }}</td>
                            <td><span title="{{ $job->created_at_date->format('d/m/Y H:i:s') }}">{{ $job->created_at_date->diffForHumans() }}</span></td>
                            <td>{{ $job->available_at_date->format('d/m/Y à H:i') }}</td>
                            <td class="text-end">
                                @if ($job->reserved_at === null)
                                    <form method="POST" action="{{ route('admin.observability.jobs.cancel', $job->id) }}" class="d-inline" onsubmit="return confirm('Annuler ce job encore en attente ?');">
                                        @csrf
                                        <button class="btn btn-sm btn-light-danger" type="submit"><i class="bi bi-x-lg"></i> Annuler</button>
                                    </form>
                                @else
                                    <button class="btn btn-sm btn-light" type="button" disabled title="Un job réservé ne peut pas être annulé sans risque.">En cours</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

<div class="card mb-8" id="scheduled-tasks">
    <div class="card-header border-0 pt-5">
        <h3 class="card-title align-items-start flex-column">
            <span class="card-label fw-bold text-gray-900">Tâches planifiées</span>
            <span class="text-muted mt-1 fw-semibold fs-7">Le heartbeat reste actif même lorsque les automatisations sont suspendues</span>
        </h3>
        <div class="card-toolbar">
            <form method="POST" action="{{ route('admin.observability.scheduler.toggle') }}" onsubmit="return confirm('{{ $scheduler['cron_enabled'] ? 'Suspendre toutes les automatisations planifiées ?' : 'Réactiver toutes les automatisations planifiées ?' }}');">
                @csrf @method('PATCH')
                <input type="hidden" name="enabled" value="{{ $scheduler['cron_enabled'] ? 0 : 1 }}">
                <button type="submit" class="btn btn-sm btn-light-{{ $scheduler['cron_enabled'] ? 'warning' : 'success' }}">
                    <i class="bi bi-{{ $scheduler['cron_enabled'] ? 'pause-circle' : 'play-circle' }} me-1"></i>
                    {{ $scheduler['cron_enabled'] ? 'Suspendre les automatisations' : 'Activer les automatisations' }}
                </button>
            </form>
        </div>
    </div>
    <div class="card-body py-3">
        <div class="table-responsive">
            <table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-4">
                <thead><tr class="fw-bold text-muted">
                    <th>Commande</th><th>Fréquence</th><th>État</th><th>Prochaine exécution</th><th>Dernier résultat</th><th class="text-end">Actions</th>
                </tr></thead>
                <tbody>
                @foreach ($tasks as $task)
                    <tr>
                        <td>
                            <code>{{ $task['command'] }}</code>
                            <span class="text-muted d-block fs-8 mt-1">{{ $task['description'] }}</span>
                        </td>
                        <td><span title="{{ $task['expression'] }}">{{ $task['frequency'] }}</span></td>
                        <td><span class="badge badge-light-{{ $task['effective_enabled'] ? 'success' : 'secondary' }}">{{ $task['effective_enabled'] ? 'Active' : 'Suspendue' }}</span></td>
                        <td>{{ $task['next_run_at']->format('d/m/Y H:i') }}</td>
                        <td>
                            @if ($task['last_finished_at'])
                                <span class="badge badge-light-{{ $task['last_result'] === 'success' ? 'success' : 'danger' }}">{{ $task['last_result'] === 'success' ? 'Succès' : 'Échec' }}</span>
                                <span class="text-muted d-block fs-8 mt-1">{{ $task['last_finished_at']->format('d/m/Y H:i:s') }}</span>
                            @else
                                <span class="text-muted">Jamais observée</span>
                            @endif
                        </td>
                        <td class="text-end text-nowrap">
                            <form method="POST" action="{{ route('admin.observability.scheduler.tasks.toggle', $task['key']) }}" class="d-inline" onsubmit="return confirm('{{ $task['enabled'] ? 'Suspendre' : 'Activer' }} cette tâche ?');">
                                @csrf @method('PATCH')
                                <input type="hidden" name="enabled" value="{{ $task['enabled'] ? 0 : 1 }}">
                                <button type="submit" class="btn btn-sm btn-light-{{ $task['enabled'] ? 'warning' : 'success' }}" title="{{ $task['enabled'] ? 'Suspendre' : 'Activer' }}" aria-label="{{ $task['enabled'] ? 'Suspendre' : 'Activer' }} {{ $task['command'] }}"><i class="bi bi-{{ $task['enabled'] ? 'pause' : 'play' }}"></i></button>
                            </form>
                            <form method="POST" action="{{ route('admin.observability.scheduler.tasks.run', $task['key']) }}" class="d-inline" onsubmit="return confirm('Exécuter {{ $task['command'] }} maintenant ?');">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-light-primary"><i class="bi bi-play-fill"></i> Exécuter</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mb-8" id="failed-jobs">
    <div class="card-header border-0 pt-5">
        <h3 class="card-title align-items-start flex-column">
            <span class="card-label fw-bold text-gray-900">Jobs échoués</span>
            <span class="text-muted mt-1 fw-semibold fs-7">Derniers {{ count($failedJobs) }} enregistrements</span>
        </h3>
        @if ($failedJobs->isNotEmpty())
            <div class="card-toolbar">
                <form method="POST" action="{{ route('admin.observability.failed-jobs.retry-all') }}" onsubmit="return confirm('Relancer tous les jobs échoués ?');">
                    @csrf
                    <button class="btn btn-sm btn-light-primary" type="submit"><i class="bi bi-arrow-repeat me-1"></i> Tout relancer</button>
                </form>
            </div>
        @endif
    </div>
    <div class="card-body py-3">
        @if ($failedJobs->isEmpty())
            <div class="text-center text-muted py-10"><i class="bi bi-shield-check fs-3x text-success mb-3 d-block"></i><div class="fw-semibold fs-6">Aucun job échoué.</div></div>
        @else
            <div class="table-responsive">
                <table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-4">
                    <thead><tr class="fw-bold text-muted"><th>Job</th><th>File</th><th>Exception</th><th>Échoué le</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    @foreach ($failedJobs as $job)
                        <tr>
                            <td><span class="fw-bold text-gray-900">{{ $job->name }}</span><span class="text-muted d-block fs-8">{{ $job->uuid }}</span></td>
                            <td><span class="badge badge-light-primary">{{ $job->queue }}</span></td>
                            <td><span class="text-danger fs-7" title="{{ $job->exception }}">{{ \Illuminate\Support\Str::limit($job->exception, 120) }}</span></td>
                            <td>{{ \Carbon\Carbon::parse($job->failed_at)->format('d/m/Y H:i:s') }}</td>
                            <td class="text-end text-nowrap">
                                <form method="POST" action="{{ route('admin.observability.failed-jobs.retry', $job->uuid) }}" class="d-inline" onsubmit="return confirm('Relancer ce job ?');">@csrf<button class="btn btn-sm btn-light-primary" type="submit"><i class="bi bi-arrow-repeat"></i> Relancer</button></form>
                                <form method="POST" action="{{ route('admin.observability.failed-jobs.forget', $job->uuid) }}" class="d-inline" onsubmit="return confirm('Oublier définitivement cet échec ?');">@csrf @method('DELETE')<button class="btn btn-sm btn-light-danger" type="submit" title="Oublier" aria-label="Oublier ce job échoué"><i class="bi bi-trash"></i></button></form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

<div class="card" id="failed-runs">
    <div class="card-header border-0 pt-5">
        <h3 class="card-title align-items-start flex-column"><span class="card-label fw-bold text-gray-900">Exécutions de campagne échouées</span><span class="text-muted mt-1 fw-semibold fs-7">Alertes métier conservées séparément des jobs Laravel</span></h3>
    </div>
    <div class="card-body py-3">
        @if ($failedRuns->isEmpty())
            <div class="text-center text-muted py-10"><i class="bi bi-shield-check fs-3x text-success mb-3 d-block"></i><div class="fw-semibold fs-6">Aucune exécution de campagne échouée.</div></div>
        @else
            <div class="table-responsive">
                <table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-4">
                    <thead><tr class="fw-bold text-muted"><th>Campagne</th><th>Exécutée le</th><th>Statut</th><th class="text-center">Envoyés</th><th class="text-center">Erreurs</th></tr></thead>
                    <tbody>
                    @foreach ($failedRuns as $run)
                        <tr>
                            <td>
                                @if ($run->campaign)
                                    <a href="{{ route('admin.campaigns.view', $run->campaign_id) }}" class="text-gray-900 fw-bold text-hover-primary">{{ $run->campaign->name }}</a>
                                @else
                                    <span class="text-muted">Campagne supprimée</span>
                                @endif
                                <span class="text-muted d-block fs-8">Exécution #{{ $run->id }}</span>
                            </td>
                            <td>{{ $run->run_at ? \Carbon\Carbon::parse($run->run_at)->format('d/m/Y H:i') : '—' }}</td>
                            <td><span class="badge badge-light-danger">{{ $run->status }}</span></td>
                            <td class="text-center fw-bold">{{ number_format($run->stats_sent ?? 0) }}</td>
                            <td class="text-center fw-bold text-danger">{{ number_format($run->stats_failed ?? 0) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

</x-default-layout>
