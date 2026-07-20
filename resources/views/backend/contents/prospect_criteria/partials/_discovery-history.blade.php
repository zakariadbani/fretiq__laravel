{{--
    ProspectCriteria — Historique des découvertes.
    Lists the 50 most recent discovery launches for the criterion, including
    separate SerpAPI calls, Hunter company lookups and contacts created.
    Variable: $model (ProspectCriteria).
    Schema::hasTable guard keeps the page safe before the discovery_runs migration.
--}}
@php
    use Illuminate\Support\Facades\Schema;
    use Carbon\CarbonInterface;

    $hasTable  = Schema::hasTable('discovery_runs');
    $runs      = $hasTable ? $model->discoveryRuns()->where('type', 'discovery')->orderByDesc('id')->limit(50)->get() : collect();
    $runsTotal = $hasTable
        ? ($runs->count() < 50 ? $runs->count() : $model->discoveryRuns()->where('type', 'discovery')->count())
        : 0;
    $hasSearchCounters = $hasTable
        && Schema::hasColumn('discovery_runs', 'searches_reserved')
        && Schema::hasColumn('discovery_runs', 'searches_consumed');
    $hasContactCounters = $hasTable
        && Schema::hasColumn('discovery_runs', 'contact_credits_reserved')
        && Schema::hasColumn('discovery_runs', 'contact_consumed');
    $hasSuccessCounters = $hasTable
        && Schema::hasColumn('discovery_runs', 'successful_enrichments')
        && Schema::hasColumn('discovery_runs', 'successful_enrichments_target');
    $searchesTotal = $runs->sum(fn ($run) => $hasSearchCounters
        ? (int) ($run->searches_consumed ?? 0)
        : (int) ($run->credits_reserved ?? 0));
    $hunterAttemptsTotal = $runs->sum(fn ($run) => $hasContactCounters
        ? (int) ($run->contact_consumed ?? 0)
        : 0);
    $contactsCreatedTotal = $runs->sum(fn ($run) => (int) ($run->contacts_count ?? 0));
    $successfulEnrichmentsTotal = $runs->sum(fn ($run) => $hasSuccessCounters
        ? (int) ($run->successful_enrichments ?? 0)
        : 0);
@endphp

<div class="card">
    <div class="card-header border-0 pt-5">
        <div>
            <h3 class="card-title fw-bolder m-0">
                <i class="bi bi-clock-history text-info fs-3 me-2"></i>
                Historique des lancements ({{ $runsTotal }})
            </h3>
            <div class="text-muted fs-7 mt-2">
                {{ $runsTotal }} lancement(s) · {{ number_format($searchesTotal) }} recherche(s) d’entreprises consommée(s)
                · {{ number_format($hunterAttemptsTotal) }} tentative(s) d’enrichissement consommée(s)
                · {{ number_format($successfulEnrichmentsTotal) }} enrichissement(s) réussi(s)
                · {{ number_format($contactsCreatedTotal) }} contact(s) créé(s).
            </div>
        </div>
    </div>
    <div class="card-body border-top p-0">
        @if($runs->isEmpty())
            <div class="text-center py-10 text-muted">
                <i class="bi bi-clock-history fs-2x mb-3 d-block"></i>
                Aucune découverte lancée pour ce critère.
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-4 mb-0">
                    <thead>
                        <tr class="fw-bold text-muted bg-light">
                            <th class="ps-7">Démarrée le</th>
                            <th>Statut</th>
                            <th>Recherches d’entreprises</th>
                            <th>Tentatives d’enrichissement</th>
                            <th>Enrichissements réussis</th>
                            <th>Traitées</th>
                            <th>Nouvelles</th>
                            <th>Contacts créés</th>
                            <th>Ignorés</th>
                            <th>Sous seuil</th>
                            <th>Durée</th>
                            <th>Terminé le</th>
                            <th class="pe-7">Erreur</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($runs as $run)
                        @php
                            $cfg = config('global.data.discovery_run_statuses.' . $run->status);
                            if ($run->started_at && $run->finished_at) {
                                // DIFF_ABSOLUTE → unsigned magnitude, so receiver order is irrelevant here (unlike signed diffIn* — see DiscoveryRun lines 66-69).
                                $duree = $run->started_at->diffForHumans($run->finished_at, [
                                    'parts'  => 1,
                                    'short'  => true,
                                    'syntax' => CarbonInterface::DIFF_ABSOLUTE,
                                ]);
                            } elseif ($run->status === 'running') {
                                $duree = 'En cours';
                            } else {
                                $duree = '—';
                            }
                            $startedDisplay = $run->started_at ?? $run->created_at;
                            $searchesConsumed = $hasSearchCounters ? (int) ($run->searches_consumed ?? 0) : (int) ($run->credits_reserved ?? 0);
                            $searchesReserved = $hasSearchCounters ? (int) ($run->searches_reserved ?? $run->credits_reserved ?? 0) : (int) ($run->credits_reserved ?? 0);
                            $hunterAttemptsConsumed = $hasContactCounters ? (int) ($run->contact_consumed ?? 0) : 0;
                            $hunterAttemptsReserved = $hasContactCounters ? (int) ($run->contact_credits_reserved ?? 0) : 0;
                            $successfulEnrichments = $hasSuccessCounters ? (int) ($run->successful_enrichments ?? 0) : 0;
                            $successfulEnrichmentsTarget = $hasSuccessCounters ? (int) ($run->successful_enrichments_target ?? 0) : 0;
                        @endphp
                        <tr>
                            <td class="ps-7 fw-semibold">{{ $startedDisplay?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td>
                                @if($cfg)
                                    <span class="badge badge-light-{{ $cfg['color'] }}">{{ $cfg['label'] }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                <span class="fw-semibold">{{ number_format($searchesConsumed) }}</span>
                                <span class="text-muted fs-8">/ {{ number_format($searchesReserved) }}</span>
                            </td>
                            <td>
                                <span class="fw-semibold">{{ number_format($hunterAttemptsConsumed) }}</span>
                                <span class="text-muted fs-8">/ {{ number_format($hunterAttemptsReserved) }}</span>
                            </td>
                            <td>
                                <span class="fw-semibold">{{ number_format($successfulEnrichments) }}</span>
                                <span class="text-muted fs-8">/ {{ number_format($successfulEnrichmentsTarget) }}</span>
                            </td>
                            <td>{{ number_format($run->companies_count) }}</td>
                            <td>{{ is_null($run->new_companies_count) ? '—' : number_format($run->new_companies_count) }}</td>
                            <td>{{ number_format($run->contacts_count) }}</td>
                            <td><span class="text-muted">{{ number_format($run->skipped_count) }}</span></td>
                            <td><span class="text-muted">{{ number_format($run->low_score_count ?? 0) }}</span></td>
                            <td>{{ $duree }}</td>
                            <td>{{ $run->finished_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td class="pe-7">
                                @if($run->status === 'failed' && $run->error)
                                    {{-- Native title tooltip; {{ }} already escapes — do NOT wrap with e(). --}}
                                    <span class="text-danger fs-8 d-inline-block text-truncate" style="max-width: 240px;"
                                          title="{{ $run->error }}">{{ $run->error }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($runsTotal > 50)
            <div class="text-center py-3 text-muted fs-7">
                Affichage des 50 dernières exécutions sur {{ $runsTotal }}.
            </div>
            @endif
        @endif
    </div>
</div>
