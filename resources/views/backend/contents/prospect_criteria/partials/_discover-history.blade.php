{{--
    ProspectCriteria — Historique des lancements Discover IA.
    Lists the 20 most recent confirmed prospect_batches for this criterion
    (source_type=discover). Variable: $model (ProspectCriteria).
--}}
@php
    $batchesQuery = $model->prospectBatches()
        ->where('source_type', 'discover')
        ->whereNotNull('cost_confirmed_at')
        ->when(! auth()->user()->hasRole(['admin', 'superadmin']),
               fn ($q) => $q->where('created_by', auth()->id()));
    $batches = (clone $batchesQuery)
        ->select(['id', 'name', 'status', 'total_items', 'review_items', 'failed_items',
                  'promoted_companies', 'imported_contacts', 'estimate', 'created_at', 'finished_at'])
        ->orderByDesc('id')->limit(20)->get();
    $batchesTotal = $batches->count() < 20 ? $batches->count() : $batchesQuery->count();
@endphp

<div class="card">
    <div class="card-header border-0 pt-5">
        <div>
            <h3 class="card-title fw-bolder m-0">
                <i class="bi bi-rocket-takeoff text-success fs-3 me-2"></i>
                Historique des lancements Discover IA ({{ $batchesTotal }})
            </h3>
        </div>
    </div>
    <div class="card-body border-top p-0">
        @if($batches->isEmpty())
            <div class="text-center py-10 text-muted">
                <i class="bi bi-rocket-takeoff fs-2x mb-3 d-block"></i>
                Aucun lancement Discover IA pour ce critère.
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-4 mb-0">
                    <thead>
                        <tr class="fw-bold text-muted bg-light">
                            <th class="ps-7">Lancé le</th>
                            <th>Statut</th>
                            <th>Trouvées</th>
                            <th>Importées</th>
                            <th>À revoir</th>
                            <th>Échecs</th>
                            <th>Crédits estimés</th>
                            <th class="pe-7">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($batches as $batch)
                        @php
                            $cfg = config('global.data.prospect_batch_statuses.' . $batch->status);
                            $needsReview = ((int) $batch->review_items + (int) $batch->failed_items) > 0;
                            $hunterCredits = data_get($batch->estimate, 'reserved_units.hunter');
                            $serpapiCredits = data_get($batch->estimate, 'reserved_units.serpapi');
                            $estimatedCredits = $hunterCredits === null && $serpapiCredits === null
                                ? null
                                : (float) $hunterCredits + (float) $serpapiCredits;
                        @endphp
                        <tr>
                            <td class="ps-7 fw-semibold">{{ $batch->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td>
                                @if($cfg)
                                    <span class="badge badge-light-{{ $cfg['color'] }}">{{ $cfg['label'] }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>{{ number_format($batch->total_items) }}</td>
                            <td>
                                {{ number_format($batch->promoted_companies) }}
                                <span class="text-muted fs-8">({{ number_format($batch->imported_contacts) }} contact(s))</span>
                            </td>
                            <td>{{ number_format($batch->review_items) }}</td>
                            <td>{{ number_format($batch->failed_items) }}</td>
                            <td>{{ $estimatedCredits === null ? '—' : number_format((float) $estimatedCredits, 2) }}</td>
                            <td class="pe-7">
                                <div class="d-flex gap-2">
                                    @can('review prospect matches')
                                        @if($needsReview)
                                            <a class="btn btn-sm btn-light-warning" href="{{ route('admin.prospect_batches.view', ['id' => $batch->id]) }}#prospect_batch_review">À vérifier</a>
                                        @endif
                                    @endcan
                                    @can('view prospect_batches')
                                        <a class="btn btn-sm btn-light" href="{{ route('admin.prospect_batches.view', ['id' => $batch->id]) }}">Voir le lot</a>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
