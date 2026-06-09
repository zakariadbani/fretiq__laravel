<x-default-layout>

@section('title')
    Zoho
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
        <li class="breadcrumb-item text-muted">Zoho</li>
    </ul>
@endsection

{{-- Sync action button (top of content, permission-gated) --}}
@can('sync zoho')
    <div class="d-flex justify-content-end mb-5">
        <form method="POST" action="{{ route('admin.zoho.sync') }}" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-sm fw-bold btn-primary">
                <i class="ki-duotone ki-arrows-circle fs-4">
                    <span class="path1"></span><span class="path2"></span>
                </i>
                Synchroniser maintenant
            </button>
        </form>
    </div>
@endcan

{{-- Flash messages --}}
@if (session('success'))
    <div class="alert alert-success d-flex align-items-center mb-6 p-5">
        <i class="ki-duotone ki-shield-tick fs-2hx text-success me-4">
            <span class="path1"></span><span class="path2"></span>
        </i>
        <div class="d-flex flex-column">
            <span class="fw-semibold fs-6">{{ e(session('success')) }}</span>
        </div>
    </div>
@endif

@if (session('error'))
    <div class="alert alert-danger d-flex align-items-center mb-6 p-5">
        <i class="ki-duotone ki-information-5 fs-2hx text-danger me-4">
            <span class="path1"></span><span class="path2"></span><span class="path3"></span>
        </i>
        <div class="d-flex flex-column">
            <span class="fw-semibold fs-6">{{ e(session('error')) }}</span>
        </div>
    </div>
@endif

{{-- On-demand notice --}}
<div class="notice d-flex bg-light-info rounded border-info border border-dashed mb-6 p-6">
    <i class="ki-duotone ki-information fs-2tx text-info me-4">
        <span class="path1"></span><span class="path2"></span><span class="path3"></span>
    </i>
    <div class="d-flex flex-stack flex-grow-1">
        <div class="fw-semibold">
            <div class="fs-6 text-gray-700">
                Synchronisation à la demande — cliquez sur "Synchroniser maintenant" pour lancer un sync immédiat.
                La synchronisation automatique est désactivée.
            </div>
        </div>
    </div>
</div>

{{-- Drivers row --}}
<div class="row g-6 mb-6">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center gap-4 py-5">
                <div class="symbol symbol-50px">
                    <div class="symbol-label bg-light-primary">
                        <i class="ki-duotone ki-cloud fs-2tx text-primary">
                            <span class="path1"></span><span class="path2"></span>
                        </i>
                    </div>
                </div>
                <div class="flex-grow-1">
                    <div class="fw-bold text-gray-800 fs-5">Driver CRM</div>
                    <div class="text-muted fs-7 mt-1">Connexion Zoho CRM</div>
                </div>
                @php
                    $crmBadge = $crmDriver === 'zoho' ? 'success' : 'secondary';
                @endphp
                <span class="badge badge-light-{{ $crmBadge }} fs-7 fw-bold">{{ e($crmDriver) }}</span>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center gap-4 py-5">
                <div class="symbol symbol-50px">
                    <div class="symbol-label bg-light-warning">
                        <i class="ki-duotone ki-sms fs-2tx text-warning">
                            <span class="path1"></span><span class="path2"></span>
                        </i>
                    </div>
                </div>
                <div class="flex-grow-1">
                    <div class="fw-bold text-gray-800 fs-5">Driver Campaigns</div>
                    <div class="text-muted fs-7 mt-1">Connexion Zoho Campaigns</div>
                </div>
                @php
                    $campBadge = $campaignsDriver === 'zoho' ? 'success' : 'secondary';
                @endphp
                <span class="badge badge-light-{{ $campBadge }} fs-7 fw-bold">{{ e($campaignsDriver) }}</span>
            </div>
        </div>
    </div>
</div>

{{-- Sync stats row --}}
@php
    $syncStatuses = config('global.data.zoho_sync_statuses', []);
    $moduleLabels = config('global.data.zoho_module_labels', []);
@endphp

<div class="row g-6 mb-6">

    {{-- Accounts card --}}
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body py-6">
                <div class="d-flex align-items-center mb-4">
                    <i class="ki-duotone ki-office-bag fs-2x text-primary me-3">
                        <span class="path1"></span><span class="path2"></span>
                        <span class="path3"></span><span class="path4"></span>
                    </i>
                    <h5 class="fw-bold text-gray-800 mb-0">Dernier sync — Comptes</h5>
                </div>
                @if ($lastByModule['Accounts'])
                    @php
                        $accountLog    = $lastByModule['Accounts'];
                        $accountStatus = $accountLog->status ?? 'idle';
                        $accountStat   = $syncStatuses[$accountStatus] ?? ['label' => $accountStatus, 'color' => 'secondary'];
                    @endphp
                    <div class="d-flex flex-column gap-2">
                        <div class="d-flex justify-content-between">
                            <span class="text-muted fs-7">Date</span>
                            <span class="fw-semibold text-gray-700 fs-7">
                                {{ $accountLog->synced_at ? $accountLog->synced_at->format('d/m/Y H:i') : '—' }}
                            </span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted fs-7">Enregistrements</span>
                            <span class="fw-bold text-{{ $accountStat['color'] }} fs-6">
                                {{ number_format((int) $accountLog->records_synced) }}
                            </span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted fs-7">Statut</span>
                            <span class="badge badge-light-{{ $accountStat['color'] }}">
                                {{ e($accountStat['label']) }}
                            </span>
                        </div>
                    </div>
                @else
                    <div class="text-muted fs-7 fst-italic">Jamais synchronisé</div>
                @endif
            </div>
        </div>
    </div>

    {{-- Contacts card --}}
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body py-6">
                <div class="d-flex align-items-center mb-4">
                    <i class="ki-duotone ki-profile-user fs-2x text-info me-3">
                        <span class="path1"></span><span class="path2"></span>
                        <span class="path3"></span><span class="path4"></span>
                    </i>
                    <h5 class="fw-bold text-gray-800 mb-0">Dernier sync — Contacts</h5>
                </div>
                @if ($lastByModule['Contacts'])
                    @php
                        $contactLog    = $lastByModule['Contacts'];
                        $contactStatus = $contactLog->status ?? 'idle';
                        $contactStat   = $syncStatuses[$contactStatus] ?? ['label' => $contactStatus, 'color' => 'secondary'];
                    @endphp
                    <div class="d-flex flex-column gap-2">
                        <div class="d-flex justify-content-between">
                            <span class="text-muted fs-7">Date</span>
                            <span class="fw-semibold text-gray-700 fs-7">
                                {{ $contactLog->synced_at ? $contactLog->synced_at->format('d/m/Y H:i') : '—' }}
                            </span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted fs-7">Enregistrements</span>
                            <span class="fw-bold text-{{ $contactStat['color'] }} fs-6">
                                {{ number_format((int) $contactLog->records_synced) }}
                            </span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted fs-7">Statut</span>
                            <span class="badge badge-light-{{ $contactStat['color'] }}">
                                {{ e($contactStat['label']) }}
                            </span>
                        </div>
                    </div>
                @else
                    <div class="text-muted fs-7 fst-italic">Jamais synchronisé</div>
                @endif
            </div>
        </div>
    </div>

    {{-- Token OAuth card --}}
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body py-6">
                <div class="d-flex align-items-center mb-4">
                    <i class="ki-duotone ki-key-square fs-2x text-warning me-3">
                        <span class="path1"></span><span class="path2"></span>
                    </i>
                    <h5 class="fw-bold text-gray-800 mb-0">Token OAuth</h5>
                </div>
                @php
                    $tokenBadgeMap = [
                        'ok'      => ['label' => 'Valide',          'color' => 'success'],
                        'soon'    => ['label' => 'Bientôt expiré',  'color' => 'warning'],
                        'expired' => ['label' => 'Expiré',          'color' => 'danger'],
                        'absent'  => ['label' => 'Absent',          'color' => 'secondary'],
                    ];
                    $tokenBadge = $tokenBadgeMap[$tokenStatus] ?? ['label' => $tokenStatus, 'color' => 'secondary'];
                @endphp
                <div class="d-flex flex-column gap-2">
                    <div class="d-flex justify-content-between">
                        <span class="text-muted fs-7">Service</span>
                        <span class="fw-semibold text-gray-700 fs-7">crm</span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted fs-7">Expiration</span>
                        @if ($tokenExpiry && $tokenMinutes !== null)
                            @if ($tokenMinutes > 0)
                                <span class="fw-bold text-{{ $tokenBadge['color'] }} fs-6">dans {{ $tokenMinutes }} min</span>
                            @else
                                <span class="fw-bold text-danger fs-6">expiré</span>
                            @endif
                        @else
                            <span class="text-muted fs-7">—</span>
                        @endif
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted fs-7">Statut</span>
                        <span class="badge badge-light-{{ $tokenBadge['color'] }}">
                            {{ e($tokenBadge['label']) }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>
{{-- end::Sync stats row --}}

{{-- ═══════════════════════════════════════════════════════════════════════════
     Driver Campaigns réel — prérequis (Phase 5)
     Affiche une checklist de prérequis avant de basculer driver=zoho.
     Le banner d'avertissement s'affiche tant que les prérequis ne sont pas tous verts.
     ═══════════════════════════════════════════════════════════════════════════ --}}
@php
    $readiness      = $campaignsReadiness ?? ['items' => [], 'ready' => false, 'driver' => 'local'];
    $readinessItems = $readiness['items'] ?? [];
    $readinessReady = $readiness['ready'] ?? false;
@endphp

{{-- Gated banner when driver=zoho but prerequisites are not met --}}
@if (($readiness['driver'] ?? 'local') === 'zoho' && ! $readinessReady)
    <div class="alert alert-danger d-flex align-items-center mb-6 p-5">
        <i class="ki-duotone ki-shield-cross fs-2hx text-danger me-4">
            <span class="path1"></span><span class="path2"></span><span class="path3"></span>
        </i>
        <div class="d-flex flex-column">
            <h4 class="mb-1 text-danger">Driver réel désactivé — prérequis manquants</h4>
            <span class="fs-6">
                Le driver <code>zoho</code> est activé dans la configuration, mais tous les prérequis
                ne sont pas remplis. Les envois échoueront jusqu'à résolution.
                Consultez la checklist ci-dessous.
            </span>
        </div>
    </div>
@endif

<div class="card mb-6">
    <div class="card-header border-0 pt-6">
        <div class="card-title">
            <h3 class="card-label fw-bold text-gray-800">Driver Campaigns réel — prérequis</h3>
        </div>
        <div class="card-toolbar">
            @if ($readinessReady)
                <span class="badge badge-light-success fs-7 fw-bold">Tous les prérequis OK</span>
            @else
                <span class="badge badge-light-warning fs-7 fw-bold">Prérequis incomplets</span>
            @endif
        </div>
    </div>
    <div class="card-body py-4">

        {{-- UNVERIFIED notice --}}
        <div class="notice d-flex bg-light-warning rounded border-warning border border-dashed mb-5 p-4">
            <i class="ki-duotone ki-information fs-2tx text-warning me-3">
                <span class="path1"></span><span class="path2"></span><span class="path3"></span>
            </i>
            <div class="d-flex flex-stack flex-grow-1">
                <div class="fw-semibold fs-7 text-gray-700">
                    <strong>UNVERIFIED</strong> — Le driver Zoho Campaigns (Phase 5) est construit mais pas vérifié en production.
                    Aucun appel API n'a reçu de STATUS 200 confirmé (OAuth Campaigns non provisionné).
                    Ne pas basculer <code>driver=zoho</code> sans : OAuth Campaigns actif + tinker live + SPF/DKIM/DMARC + gestion des bounces + aval légal TCL.
                </div>
            </div>
        </div>

        <table class="table align-middle table-row-dashed fs-6 gy-4">
            <thead>
                <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                    <th class="min-w-250px">Prérequis</th>
                    <th class="min-w-80px">Statut</th>
                    <th>Note</th>
                </tr>
            </thead>
            <tbody class="text-gray-600 fw-semibold">
                @forelse ($readinessItems as $key => $item)
                    <tr>
                        <td class="fw-bold text-gray-800">{{ e($item['label']) }}</td>
                        <td>
                            @if ($item['status'])
                                <span class="badge badge-light-success">
                                    <i class="ki-duotone ki-check fs-6 me-1"><span class="path1"></span><span class="path2"></span></i>
                                    OK
                                </span>
                            @else
                                <span class="badge badge-light-danger">
                                    <i class="ki-duotone ki-cross fs-6 me-1"><span class="path1"></span></i>
                                    Manquant
                                </span>
                            @endif
                        </td>
                        <td class="text-muted fs-7">{{ e($item['note']) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="text-center text-muted py-6 fst-italic">
                            Aucun prérequis défini.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Historique --}}
<div class="card">
    <div class="card-header border-0 pt-6">
        <div class="card-title">
            <h3 class="card-label fw-bold text-gray-800">Historique de synchronisation</h3>
        </div>
    </div>
    <div class="card-body py-4">
        <table class="table align-middle table-row-dashed fs-6 gy-5">
            <thead>
                <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                    <th class="min-w-100px">Module</th>
                    <th class="min-w-160px">Date</th>
                    <th class="min-w-80px">Récupérés</th>
                    <th class="min-w-100px">Statut</th>
                </tr>
            </thead>
            <tbody class="text-gray-600 fw-semibold">
                @forelse ($history as $log)
                    @php
                        $logStatus = $log->status ?? 'idle';
                        $logStat   = $syncStatuses[$logStatus] ?? ['label' => $logStatus, 'color' => 'secondary'];
                        $modLabel  = $moduleLabels[$log->module] ?? $log->module;
                        $modColor  = $log->module === 'Contacts' ? 'primary' : 'info';
                    @endphp
                    <tr>
                        <td>
                            <span class="badge badge-light-{{ $modColor }}">
                                {{ e($modLabel) }}
                            </span>
                        </td>
                        <td>{{ $log->synced_at ? $log->synced_at->format('d/m/Y H:i') : '—' }}</td>
                        <td class="fw-bold text-{{ $logStat['color'] }}">
                            {{ number_format((int) $log->records_synced) }}
                        </td>
                        <td>
                            <span class="badge badge-light-{{ $logStat['color'] }}">
                                {{ e($logStat['label']) }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-center text-muted py-8 fst-italic">
                            Aucune synchronisation enregistrée.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

</x-default-layout>
