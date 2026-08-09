<x-default-layout>

@section('title', 'Synchronisation & qualité des données')
@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Administration'], ['label' => 'Zoho']]" />
@endsection

@php
    $queue = $dashboard['queue'];
    $qualityLabels = [
        'missing_owner' => 'Propriétaire manquant', 'missing_email' => 'Email manquant',
        'missing_account_zoho_id' => 'Compte manquant', 'missing_stage' => 'Étape manquante',
        'missing_currency_code' => 'Devise manquante', 'missing_quote_items' => 'Lignes de devis manquantes',
        'orphans' => 'Relations orphelines', 'stale' => 'Enregistrements obsolètes',
    ];
    $actionModules = collect($dashboard['modules'])->filter(
        fn (array $module, string $key) => $module['active'] && $key !== 'quoted_items'
    );
    $reconciliationLabels = [
        'healthy' => 'Saine', 'degraded' => 'Dégradée', 'success' => 'Réussie',
        'partial' => 'Partielle', 'error' => 'En erreur', 'non disponible' => 'Non disponible',
    ];
@endphp

@if(config('zoho-v2.features.sync_enabled', false))
<div class="d-flex flex-wrap justify-content-end align-items-end gap-3 mb-6">
    @can('sync zoho')
        <form method="POST" action="{{ route('admin.zoho.v2.sync') }}" class="d-flex flex-column flex-sm-row gap-2">
            @csrf
            <label class="visually-hidden" for="zoho-delta-module">Module à synchroniser en delta</label>
            <select class="form-select form-select-sm w-auto" id="zoho-delta-module" name="module">
                <option value="">Tous les modules actifs</option>
                @foreach($actionModules as $key => $module)<option value="{{ $key }}">{{ $module['label'] }}</option>@endforeach
            </select>
            <button type="submit" class="btn btn-sm btn-primary text-nowrap"><i class="bi bi-arrow-repeat"></i> Synchronisation delta</button>
        </form>
        <form method="POST" action="{{ route('admin.zoho.v2.retry') }}">@csrf<button type="submit" class="btn btn-sm btn-light-warning">Réessayer toutes les anomalies</button></form>
    @endcan
    @can('backfill zoho')
        <form method="POST" action="{{ route('admin.zoho.v2.backfill') }}" class="d-flex flex-column flex-sm-row gap-2">
            @csrf<input type="hidden" name="mode" value="backfill">
            <label class="visually-hidden" for="zoho-backfill-module">Module à reprendre en backfill</label>
            <select class="form-select form-select-sm w-auto" id="zoho-backfill-module" name="module">
                <option value="">Tous les modules actifs</option>
                @foreach($actionModules as $key => $module)<option value="{{ $key }}">{{ $module['label'] }}</option>@endforeach
            </select>
            <button type="submit" class="btn btn-sm btn-light-primary text-nowrap">Backfill</button>
        </form>
        <form method="POST" action="{{ route('admin.zoho.v2.backfill') }}" class="d-flex flex-column flex-sm-row gap-2">
            @csrf<input type="hidden" name="mode" value="reconcile">
            <label class="visually-hidden" for="zoho-reconcile-module">Module à réconcilier</label>
            <select class="form-select form-select-sm w-auto" id="zoho-reconcile-module" name="module">
                <option value="">Tous les modules actifs</option>
                @foreach($actionModules as $key => $module)<option value="{{ $key }}">{{ $module['label'] }}</option>@endforeach
            </select>
            <button type="submit" class="btn btn-sm btn-light-info text-nowrap">Réconciliation</button>
        </form>
    @endcan
</div>
@else
<div class="notice bg-light-warning border border-warning border-dashed rounded p-4 mb-6">Les commandes V2 sont désactivées par le drapeau de déploiement.</div>
@endif

<div class="notice d-flex bg-light-info rounded border-info border border-dashed mb-6 p-5" role="status">
    <i class="bi bi-shield-check fs-2x text-info me-4"></i>
    <div><strong>Lecture seule.</strong> Zoho reste la source de vérité. Crédits API : <strong>non fourni par Zoho</strong>. Les requêtes locales restent comptabilisées.</div>
</div>

<div class="row g-5 mb-6">
    <div class="col-md-3"><div class="card h-100"><div class="card-body"><span class="text-muted fs-7">Santé globale</span><div class="fs-4 fw-bold text-{{ $dashboard['overall']['color'] }}">{{ $dashboard['overall']['label'] }}</div><div class="fs-8 text-muted">Plus ancien succès : {{ $dashboard['overall']['oldest_minutes'] === null ? 'non disponible' : $dashboard['overall']['oldest_minutes'].' min' }}</div></div></div></div>
    <div class="col-md-3"><div class="card h-100"><div class="card-body"><span class="text-muted fs-7">OAuth CRM</span><div class="fs-4 fw-bold text-{{ in_array($dashboard['oauth'], ['ready','soon']) ? 'success' : 'warning' }}">{{ $dashboard['oauth'] === 'ready' ? 'Prêt' : ($dashboard['oauth'] === 'soon' ? 'Expire bientôt' : 'À vérifier') }}</div><div class="fs-8 text-muted">{{ $dashboard['tokenExpiry']?->format('d/m/Y H:i') ?? 'Aucune échéance disponible' }}</div></div></div></div>
    <div class="col-md-3"><div class="card h-100"><div class="card-body"><span class="text-muted fs-7">File Zoho</span><div class="fs-4 fw-bold">{{ $queue['waiting'] }} en attente</div><div class="fs-8 text-muted">{{ $queue['reserved'] }} réservée(s), {{ $queue['failed'] }} échec(s). Worker : {{ $queue['state'] === 'observé' ? 'activité observée, disponibilité inconnue' : 'inconnu' }}.</div></div></div></div>
    <div class="col-md-3"><div class="card h-100"><div class="card-body"><span class="text-muted fs-7">Lot / anomalies</span><div class="fs-4 fw-bold">{{ $dashboard['batch'] ? '#'.$dashboard['batch']->id : 'Aucun lot' }}</div><div class="fs-8 text-muted">{{ $dashboard['openFailureCount'] }} anomalie(s) ouverte(s), sans suppression des preuves de file.</div></div></div></div>
</div>

<div class="card mb-6">
    <div class="card-header"><h3 class="card-title">Modules, fraîcheur et qualité</h3></div>
    <div class="card-body p-0"><div class="table-responsive"><table class="table table-row-dashed align-middle gy-4 mb-0">
        <thead><tr class="text-muted text-uppercase fs-8"><th>Module</th><th>Exécutions</th><th>Checkpoint</th><th>Comptages</th><th>Réconciliation</th><th>Qualité</th><th>Actions</th></tr></thead>
        <tbody>
        @forelse($dashboard['modules'] as $key => $module)
            @php
                $counts = $dashboard['counts'][$key];
                $attempt = $module['attempt'];
                $checkpoint = $module['checkpoint'];
                $reconciliation = $module['reconciliation'];
            @endphp
            <tr>
                <td><div class="fw-bold">{{ $module['label'] }}</div><span class="badge badge-light-{{ $module['freshness']['color'] }}">{{ $module['freshness']['label'] }}</span>@if(!$module['active'])<div class="fs-8 text-muted mt-1">{{ $module['note'] }}</div>@endif</td>
                <td class="fs-8">Tentative : {{ $attempt?->synced_at?->format('d/m/Y H:i') ?? 'jamais' }} ({{ $attempt?->status ?? '—' }})<br>Succès : {{ $module['success']?->synced_at?->format('d/m/Y H:i') ?? 'jamais' }}<br>Durée : {{ $attempt?->duration_ms === null ? '—' : number_format($attempt->duration_ms).' ms' }} · {{ number_format((int)($attempt?->api_requests ?? 0)) }} requêtes<br>Vus/créés/modifiés/identiques/quarantainés : {{ (int)($attempt?->records_seen ?? 0) }}/{{ (int)($attempt?->records_created ?? 0) }}/{{ (int)($attempt?->records_updated ?? 0) }}/{{ (int)($attempt?->records_unchanged ?? 0) }}/{{ (int)($attempt?->records_quarantined ?? 0) }}</td>
                <td class="fs-8">{{ $checkpoint?->sync_mode ?? '—' }} · {{ $checkpoint?->status ?? '—' }}<br>Curseur : {{ $checkpoint?->cursor_at?->format('d/m/Y H:i') ?? ($checkpoint?->cursor_zoho_id ?? '—') }}<br>Page : {{ $checkpoint?->cursor_page_token ? 'en cours' : 'aucune' }} · reprises : {{ (int)($checkpoint?->retry_count ?? 0) }}<br>Terminé : {{ $checkpoint?->completed_at?->format('d/m/Y H:i') ?? 'non' }}</td>
                <td>{{ number_format($counts['active']) }} actifs<br>{{ number_format($counts['tombstoned']) }} supprimés conservés</td>
                <td class="fs-8">{{ $reconciliationLabels[$reconciliation['status']] ?? ucfirst((string)$reconciliation['status']) }} · <span class="badge badge-light-{{ $module['reconciliation_freshness']['color'] }}">{{ $module['reconciliation_freshness']['label'] }}</span><br>Distant/local : {{ $reconciliation['remote'] ?? 'non disponible' }} / {{ $reconciliation['local'] ?? 'non disponible' }}<br>Manquants/excédents : {{ $reconciliation['missing'] ?? '—' }} / {{ $reconciliation['extra'] ?? '—' }}<br>Pages / HTTP : {{ $reconciliation['pages'] ?? '—' }} / {{ $reconciliation['http_status'] ?? '—' }} · complète : {{ $reconciliation['complete'] === null ? 'non disponible' : ($reconciliation['complete'] ? 'oui' : 'non') }}<br>Suppressions tombstonées : {{ $reconciliation['tombstoned'] ?? '—' }}</td>
                <td class="fs-8">@forelse($module['quality'] as $metric => $value)<div>{{ $qualityLabels[$metric] ?? $metric }} : {{ number_format($value) }}</div>@empty<span class="text-muted">Non disponible</span>@endforelse<div class="mt-1">Schéma : <span class="badge badge-light-{{ ($module['manifest']?->drift_state ?? null) === 'verified' ? 'success' : 'warning' }}">{{ $module['manifest']?->drift_state ?? 'non vérifié' }}</span></div></td>
                <td>
                    @if(config('zoho-v2.features.sync_enabled', false) && $module['active'] && $key !== 'quoted_items')
                        @can('sync zoho')
                            <form method="POST" action="{{ route('admin.zoho.v2.retry') }}">
                                @csrf<input type="hidden" name="module" value="{{ $key }}">
                                <button type="submit" class="btn btn-sm btn-light-warning text-nowrap" aria-label="Réessayer le module {{ $module['label'] }}">Réessayer</button>
                            </form>
                        @endcan
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center text-muted py-8">Aucun module V2 disponible.</td></tr>
        @endforelse
        </tbody>
    </table></div></div>
</div>

<div class="row g-6 mb-6">
    <div class="col-lg-6"><div class="card h-100"><div class="card-header"><h3 class="card-title">Schémas vérifiés</h3></div><div class="card-body"><ul class="list-group list-group-flush">@forelse($dashboard['manifests'] as $manifest)<li class="list-group-item d-flex justify-content-between"><span>{{ $manifest->module }}{{ $manifest->submodule ? ' / '.$manifest->submodule : '' }}</span><span class="badge badge-light-{{ $manifest->drift_state === 'verified' ? 'success' : 'warning' }}">{{ $manifest->drift_state }}</span></li>@empty<li class="list-group-item text-muted">Aucun manifeste inventorié.</li>@endforelse</ul></div></div></div>
    <div class="col-lg-6"><div class="card h-100"><div class="card-header"><h3 class="card-title">Fiabilité récente</h3></div><div class="card-body"><ul class="list-group list-group-flush">@forelse($dashboard['trend'] as $log)<li class="list-group-item d-flex justify-content-between"><span>{{ $log->module }} · {{ $log->synced_at?->format('d/m H:i') }}</span><span><span class="badge badge-light-{{ $log->status === 'success' ? 'success' : 'warning' }}">{{ $log->status }}</span> {{ number_format((int)$log->records_seen) }} vus</span></li>@empty<li class="list-group-item text-muted">Aucune exécution V2.</li>@endforelse</ul></div></div></div>
</div>

<div class="card">
    <div class="card-header"><h3 class="card-title">Anomalies redigées et corrélation</h3></div>
    <div class="card-body p-0"><div class="table-responsive"><table class="table table-row-dashed align-middle mb-0"><thead><tr><th>Module</th><th>Catégorie sûre</th><th>Corrélation</th><th>Essais</th><th>Action</th></tr></thead><tbody>
    @forelse($dashboard['failures'] as $failure)
        @php($failureModule = $dashboard['modules'][$failure->module] ?? null)
        <tr><td>{{ $failure->module }}</td><td>{{ str_replace('_', ' ', $failure->failure_kind) }}</td><td><code>{{ $failure->correlation_id }}</code></td><td>{{ $failure->attempts }}</td><td>
            @if(config('zoho-v2.features.sync_enabled', false) && $failureModule && $failureModule['active'] && $failure->module !== 'quoted_items')
                @can('sync zoho')
                    <form method="POST" action="{{ route('admin.zoho.v2.retry') }}">
                        @csrf<input type="hidden" name="module" value="{{ $failure->module }}">
                        <button type="submit" class="btn btn-sm btn-light-warning text-nowrap" aria-label="Réessayer les anomalies du module {{ $failure->module }}">Réessayer</button>
                    </form>
                @endcan
            @else
                <span class="text-muted">—</span>
            @endif
        </td></tr>
    @empty<tr><td colspan="5" class="text-center text-muted py-8">Aucune anomalie ouverte.</td></tr>@endforelse
    </tbody></table></div></div>
</div>

@can('manage zoho mappings')
<div class="card mt-6">
    <div class="card-header"><h3 class="card-title">Correspondances utilisateurs</h3></div>
    <div class="card-body p-0"><div class="table-responsive"><table class="table table-row-dashed align-middle mb-0"><thead><tr><th>Utilisateur Zoho</th><th>Correspondance Fretiq</th><th>Méthode</th><th>Action</th></tr></thead><tbody>
    @forelse($dashboard['identities'] as $identity)
        @php($mapping = $dashboard['mappings']->get($identity['zoho_id']))
        <tr><td>{{ $identity['label'] ?: 'Propriétaire Zoho observé' }}<div class="fs-8 text-muted"><code>{{ $identity['zoho_id'] }}</code></div></td><td>{{ $dashboard['fretiqUsers']->firstWhere('id', $mapping?->fretiq_user_id)?->name ?? 'Non attribué' }}</td><td>{{ $mapping?->match_method ?? '—' }}</td><td><form method="POST" action="{{ route('admin.zoho.v2.mapping') }}" class="d-flex gap-2">@csrf<input type="hidden" name="zoho_user_id" value="{{ $identity['zoho_id'] }}"><label class="visually-hidden" for="mapping-{{ $identity['zoho_id'] }}">Utilisateur Fretiq</label><select class="form-select form-select-sm" id="mapping-{{ $identity['zoho_id'] }}" name="fretiq_user_id"><option value="">Dissocier</option>@foreach($dashboard['fretiqUsers'] as $user)<option value="{{ $user->id }}" @selected($mapping?->fretiq_user_id === $user->id)>{{ $user->name }}</option>@endforeach</select><button class="btn btn-sm btn-light-primary">Confirmer</button></form></td></tr>
    @empty<tr><td colspan="4" class="text-center text-muted py-8">Aucun utilisateur Zoho lisible. Les rapprochements automatiques utilisent uniquement un email exact.</td></tr>@endforelse
    </tbody></table></div></div>
</div>
@endcan
</x-default-layout>
