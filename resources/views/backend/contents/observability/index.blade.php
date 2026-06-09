<x-default-layout>

@section('title')
    Observabilité de la file d'attente
@endsection

@section('breadcrumbs')
    <ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
        <li class="breadcrumb-item text-muted">
            <a href="{{ route('admin.dashboard') }}" class="text-muted text-hover-primary">Accueil</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">Administration</li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">Observabilité</li>
    </ul>
@endsection

{{-- ====================================================================== --}}
{{-- Compteurs de santé (4 cartes)                                           --}}
{{-- ====================================================================== --}}
<div class="row g-5 g-xl-8 mb-8">

    {{-- Jobs échoués --}}
    <div class="col-xl-3 col-md-6">
        <div class="card bg-body hoverable card-xl-stretch mb-xl-8">
            <div class="card-body">
                <i class="ki-duotone ki-cross-circle fs-2x {{ $counts['failed_jobs'] > 0 ? 'text-danger' : 'text-success' }}">
                    <span class="path1"></span><span class="path2"></span>
                </i>
                <div class="text-gray-900 fw-bold fs-2 mb-2 mt-5">{{ number_format($counts['failed_jobs']) }}</div>
                <div class="fw-semibold text-gray-600">Jobs échoués</div>
                @if ($counts['failed_jobs'] > 0)
                    <div class="mt-2"><span class="badge badge-light-danger fs-8">Action requise</span></div>
                @else
                    <div class="mt-2"><span class="badge badge-light-success fs-8">OK</span></div>
                @endif
            </div>
        </div>
    </div>

    {{-- Exécutions de campagne échouées --}}
    <div class="col-xl-3 col-md-6">
        <div class="card bg-body hoverable card-xl-stretch mb-xl-8">
            <div class="card-body">
                <i class="ki-duotone ki-rocket fs-2x {{ $counts['failed_runs'] > 0 ? 'text-warning' : 'text-success' }}">
                    <span class="path1"></span><span class="path2"></span>
                </i>
                <div class="text-gray-900 fw-bold fs-2 mb-2 mt-5">{{ number_format($counts['failed_runs']) }}</div>
                <div class="fw-semibold text-gray-600">Exécutions échouées</div>
                @if ($counts['failed_runs'] > 0)
                    <div class="mt-2"><span class="badge badge-light-warning fs-8">À vérifier</span></div>
                @else
                    <div class="mt-2"><span class="badge badge-light-success fs-8">OK</span></div>
                @endif
            </div>
        </div>
    </div>

    {{-- Destinataires en attente --}}
    <div class="col-xl-3 col-md-6">
        <div class="card bg-body hoverable card-xl-stretch mb-xl-8">
            <div class="card-body">
                <i class="ki-duotone ki-timer fs-2x text-info">
                    <span class="path1"></span><span class="path2"></span><span class="path3"></span>
                </i>
                <div class="text-gray-900 fw-bold fs-2 mb-2 mt-5">{{ number_format($counts['queued_recipients']) }}</div>
                <div class="fw-semibold text-gray-600">Destinataires en file</div>
                <div class="mt-2"><span class="badge badge-light-info fs-8">En attente d'envoi</span></div>
            </div>
        </div>
    </div>

    {{-- Exécutions planifiées --}}
    <div class="col-xl-3 col-md-6">
        <div class="card bg-body hoverable card-xl-stretch mb-xl-8">
            <div class="card-body">
                <i class="ki-duotone ki-calendar-tick fs-2x text-primary">
                    <span class="path1"></span><span class="path2"></span>
                    <span class="path3"></span><span class="path4"></span><span class="path5"></span>
                </i>
                <div class="text-gray-900 fw-bold fs-2 mb-2 mt-5">{{ number_format($counts['scheduled_runs']) }}</div>
                <div class="fw-semibold text-gray-600">Exécutions planifiées</div>
                <div class="mt-2"><span class="badge badge-light-primary fs-8">Programmées</span></div>
            </div>
        </div>
    </div>

</div>
{{-- end::Counts --}}

{{-- ====================================================================== --}}
{{-- Table : Jobs échoués (failed_jobs)                                      --}}
{{-- ====================================================================== --}}
<div class="card mb-8">
    <div class="card-header border-0 pt-5">
        <h3 class="card-title align-items-start flex-column">
            <span class="card-label fw-bold text-gray-900">Jobs échoués</span>
            <span class="text-muted mt-1 fw-semibold fs-7">Derniers {{ count($failedJobs) }} enregistrements — table <code>failed_jobs</code></span>
        </h3>
    </div>
    <div class="card-body py-3">
        @if ($failedJobs->isEmpty())
            <div class="text-center text-muted py-10">
                <i class="ki-duotone ki-shield-tick fs-3x text-success mb-3 d-block">
                    <span class="path1"></span><span class="path2"></span>
                </i>
                <div class="fw-semibold fs-6">Aucun job échoué. La file d'attente est saine.</div>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-4">
                    <thead>
                        <tr class="fw-bold text-muted">
                            <th class="min-w-60px">#</th>
                            <th class="min-w-100px">File</th>
                            <th class="min-w-120px">Connexion</th>
                            <th class="min-w-300px">Exception</th>
                            <th class="min-w-150px">Échoué le</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($failedJobs as $job)
                            <tr>
                                <td>
                                    <span class="text-muted fw-semibold fs-7">{{ $job->id }}</span>
                                </td>
                                <td>
                                    <span class="badge badge-light-primary">{{ e($job->queue) }}</span>
                                </td>
                                <td>
                                    <span class="text-gray-700 fs-7">{{ e($job->connection) }}</span>
                                </td>
                                <td>
                                    <span class="text-danger fw-semibold fs-7" title="{{ e($job->exception) }}">
                                        {{ e(\Illuminate\Support\Str::limit($job->exception, 120)) }}
                                    </span>
                                </td>
                                <td>
                                    <span class="text-muted fs-7">
                                        {{ \Carbon\Carbon::parse($job->failed_at)->format('d/m/Y H:i:s') }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
{{-- end::Failed Jobs --}}

{{-- ====================================================================== --}}
{{-- Table : Exécutions de campagne échouées (failed_runs)                   --}}
{{-- ====================================================================== --}}
<div class="card">
    <div class="card-header border-0 pt-5">
        <h3 class="card-title align-items-start flex-column">
            <span class="card-label fw-bold text-gray-900">Exécutions de campagne échouées</span>
            <span class="text-muted mt-1 fw-semibold fs-7">Exécutions avec statut <code>failed</code></span>
        </h3>
    </div>
    <div class="card-body py-3">
        @if ($failedRuns->isEmpty())
            <div class="text-center text-muted py-10">
                <i class="ki-duotone ki-shield-tick fs-3x text-success mb-3 d-block">
                    <span class="path1"></span><span class="path2"></span>
                </i>
                <div class="fw-semibold fs-6">Aucune exécution de campagne échouée.</div>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-4">
                    <thead>
                        <tr class="fw-bold text-muted">
                            <th class="min-w-200px">Campagne</th>
                            <th class="min-w-150px">Exécutée le</th>
                            <th class="min-w-80px text-center">Statut</th>
                            <th class="min-w-80px text-center">Envoyés</th>
                            <th class="min-w-80px text-center">Erreurs</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($failedRuns as $run)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="symbol symbol-40px me-3">
                                            <div class="symbol-label bg-light-danger">
                                                <i class="ki-duotone ki-rocket fs-3 text-danger">
                                                    <span class="path1"></span><span class="path2"></span>
                                                </i>
                                            </div>
                                        </div>
                                        <div>
                                            @if ($run->campaign)
                                                <a href="{{ route('admin.campaigns.view', $run->campaign_id) }}"
                                                   class="text-gray-900 fw-bold text-hover-primary fs-6">
                                                    {{ e($run->campaign->name) }}
                                                </a>
                                            @else
                                                <span class="text-muted fw-semibold fs-6">Campagne supprimée</span>
                                            @endif
                                            <span class="text-muted fw-semibold d-block fs-7">
                                                Exécution #{{ $run->id }}
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="text-muted fs-7">
                                        {{ $run->run_at ? \Carbon\Carbon::parse($run->run_at)->format('d/m/Y H:i') : '—' }}
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-light-danger">{{ e($run->status) }}</span>
                                </td>
                                <td class="text-center">
                                    <span class="fw-bold text-gray-900">{{ number_format($run->stats_sent ?? 0) }}</span>
                                </td>
                                <td class="text-center">
                                    <span class="fw-bold text-danger">{{ number_format($run->stats_failed ?? 0) }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
{{-- end::Failed Runs --}}

</x-default-layout>
