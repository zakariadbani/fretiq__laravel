<div class="card">
    <div class="card-header border-0 pt-5"><h3 class="card-title fw-bolder m-0"><i class="bi bi-list-check text-info fs-3 me-2"></i>Historique ({{ $runs->count() }})</h3></div>
    @if($runs->isNotEmpty())
    <div class="card-body border-top p-0"><div class="table-responsive"><table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-4 mb-0">
        <thead><tr class="fw-bold text-muted bg-light"><th class="ps-7">Date</th><th>Étape</th><th>Statut</th><th>Envoyés</th><th>Ouverts</th><th>Clics</th><th>Rebonds</th><th>Terminé le</th><th class="text-end pe-7">Actions</th></tr></thead>
        <tbody>@foreach($runs as $run)
            @php($state = $timelineRunStates[$run->id] ?? [])
            @php($kpis = $run->kpis())
            @php($canManageRun = (auth()->user()?->can('send campaigns') && (($state['can_start'] ?? false) || ($state['can_resend'] ?? false)))
                || (auth()->user()?->can('edit campaigns') && ($state['can_cancel'] ?? false)))
            <tr data-run-id="{{ $run->id }}">
                <td class="ps-7 fw-semibold">{{ $run->run_at?->copy()->setTimezone($model->scheduleTimezone())->format('d/m/Y H:i') ?? '—' }}</td>
                <td>
                    @if($run->sequenceStep)
                        Étape {{ $run->sequenceStep->step_no }} — {{ $run->sequenceStep->subject ?: $run->sequenceStep->template?->subject ?: 'Sans objet' }}
                    @else
                        Campagne
                    @endif
                </td>
                <td><span class="badge badge-light-{{ $state['color'] ?? 'secondary' }}">{{ $state['label'] ?? '—' }}</span>
                    @if(!empty($state['reserved_for']))<div class="text-muted fs-8 mt-1">Prochain créneau sûr : {{ $state['reserved_for']->format('d/m/Y H:i') }}</div>@endif
                    @if(!empty($state['reason']))<div class="text-muted fs-8 mt-1">{{ $state['reason'] }}</div>@endif
                    @if(!empty($state['source']))<div class="text-muted fs-8 mt-1">{{ $state['source'] }}</div>@endif
                </td>
                <td>{{ number_format($kpis['sent'] ?? 0) }}</td>
                <td>{{ number_format($kpis['opened'] ?? 0) }} <span class="text-muted fs-8">({{ \App\Models\CampaignRun::rateLabel($kpis['open_rate'] ?? null) }})</span></td>
                <td>{{ number_format($kpis['clicked'] ?? 0) }} <span class="text-muted fs-8">({{ \App\Models\CampaignRun::rateLabel($kpis['click_rate'] ?? null) }})</span></td>
                <td>{{ number_format($kpis['bounced'] ?? 0) }}</td>
                <td>{{ $run->finished_at?->copy()->setTimezone($model->scheduleTimezone())->format('d/m/Y H:i') ?? '—' }}</td>
                <td class="text-end pe-7"><a href="{{ route('admin.campaigns.view', $model->id) }}?run_id={{ $run->id }}#campaign_destinataires" class="btn btn-sm btn-light-primary"><i class="bi bi-envelope me-1"></i>Voir destinataires</a>
                    @if($canManageRun)<div class="dropdown d-inline-block ms-1"><button type="button" class="btn btn-sm btn-light dropdown-toggle" data-bs-toggle="dropdown">Gérer</button><div class="dropdown-menu dropdown-menu-end p-2">
                        @can('send campaigns')@if($state['can_start'] ?? false)<button type="button" class="dropdown-item rounded py-2 timeline-action" data-action="start" data-url="{{ route('admin.campaigns.runs.startNow', [$model, $run]) }}"><i class="bi bi-play-circle me-2"></i>Démarrer maintenant</button>@endif @if($state['can_resend'] ?? false)<button type="button" class="dropdown-item rounded py-2 timeline-action" data-action="resend" data-url="{{ route('admin.campaigns.runs.resend', [$model, $run]) }}"><i class="bi bi-arrow-repeat me-2"></i>Renvoyer ce lot</button>@endif @endcan
                        @can('edit campaigns')@if($state['can_cancel'] ?? false)<button type="button" class="dropdown-item rounded py-2 text-danger timeline-action" data-action="cancel" data-url="{{ route('admin.campaigns.runs.cancel', [$model, $run]) }}"><i class="bi bi-x-circle me-2"></i>Annuler ce lot</button>@endif @endcan
                    </div></div>@endif
                </td>
            </tr>
        @endforeach</tbody>
    </table></div></div>
    @else <div class="card-body text-center py-8 text-muted">Aucune exécution pour cette campagne.</div>@endif
</div>
