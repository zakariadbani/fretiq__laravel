{{--
    Historique tab pane — full execution history for a campaign.

    Expects:
        $runs   — Collection of CampaignRun (already eager-loaded in view())
        $model  — Campaign
--}}

<div class="card">
    <div class="card-header border-0 pt-5">
        <h3 class="card-title fw-bolder m-0">
            <i class="bi bi-list-check text-info fs-3 me-2"></i>
            Exécutions ({{ $runs->count() }})
        </h3>
    </div>

    @if($runs->count())
    <div class="card-body border-top p-0">
        <div class="table-responsive">
            <table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-4 mb-0">
                <thead>
                    <tr class="fw-bold text-muted bg-light">
                        <th class="ps-7">Date</th>
                        <th>Statut</th>
                        <th>Envoyés</th>
                        <th>Ouverts</th>
                        <th>Clics</th>
                        <th>Rebonds</th>
                        <th>Terminé le</th>
                        <th class="text-end pe-7">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($runs as $run)
                    @php
                        $runStatusCfg = config('global.data.campaign_run_statuses.' . $run->status);
                    @endphp
                    <tr>
                        <td class="ps-7 fw-semibold">{{ $run->run_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td>
                            @if($runStatusCfg)
                                <span class="badge badge-light-{{ $runStatusCfg['color'] }}">{{ $runStatusCfg['label'] }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>{{ number_format($run->stats_sent ?? 0) }}</td>
                        <td>{{ number_format($run->stats_opened ?? 0) }} <span class="text-muted fs-8">({{ $run->openRate() }}%)</span></td>
                        <td>{{ number_format($run->stats_clicked ?? 0) }} <span class="text-muted fs-8">({{ $run->clickRate() }}%)</span></td>
                        <td>{{ number_format($run->stats_bounced ?? 0) }}</td>
                        <td>{{ $run->finished_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td class="text-end pe-7">
                            <a href="{{ route('admin.campaigns.view', $model->id) }}?run_id={{ $run->id }}#campaign_destinataires"
                               class="btn btn-sm btn-light-primary">
                                <i class="bi bi-envelope me-1"></i>Voir destinataires
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @else
    <div class="card-body text-center py-8 text-muted">
        <i class="bi bi-list-check fs-2x mb-3 d-block"></i>
        Aucune exécution pour cette campagne.
    </div>
    @endif
</div>
