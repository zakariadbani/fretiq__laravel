@php
    $recipientStatuses = config('global.data.campaign_recipient_statuses', []);
    $skipReasons = config('global.data.campaign_recipient_skip_reasons', []);
    $enrollmentStatuses = config('global.data.sequence_enrollment_statuses', []);
    $selectedSummary = ($waves ?? collect())->first(fn ($wave) => $selectedWave && $wave['run']->id === $selectedWave->id);
    $zohoCampaignsUrl = preg_replace('#/api/.*$#', '', (string) config('services.zoho.campaigns.api_url', 'https://campaigns.zoho.com/api/v1.1'));
@endphp

<div class="row g-5">
    <div class="col-xl-5">
        <div class="card h-100">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title fw-bolder m-0"><i class="bi bi-layers text-primary fs-3 me-2"></i>Vagues ({{ $waves->count() }})</h3>
            </div>
            <div class="card-body border-top p-0">
                @forelse($waves as $wave)
                    @php
                        $isSelected = $selectedWave && $selectedWave->id === $wave['run']->id;
                    @endphp
                    <a href="{{ route('admin.campaigns.view', $model->id) }}?wave_id={{ $wave['run']->id }}#campaign_vagues"
                       class="d-block p-5 border-bottom text-gray-800 {{ $isSelected ? 'bg-light-primary' : 'bg-hover-light' }}">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-bold fs-6">Vague {{ str_pad((string) $wave['number'], 3, '0', STR_PAD_LEFT) }}</div>
                                <div class="text-muted fs-7">{{ $wave['run']->run_at?->copy()->setTimezone($model->scheduleTimezone())->format('d/m/Y H:i') }}</div>
                            </div>
                            @if($wave['empty'])
                                <span class="badge badge-light-secondary">Aucun contact éligible</span>
                            @elseif($wave['run']->status === 'failed' || $wave['run']->driver_ref === 'zoho-wave-failed')
                                <span class="badge badge-light-danger">Echec Zoho</span>
                            @elseif($wave['run']->driver_ref === 'zoho-wave-synced')
                                <span class="badge badge-light-success">Synchronisee</span>
                            @elseif($wave['run']->status === 'sent' && (filled($wave['run']->zoho_campaign_key) || $wave['run']->driver_ref === 'zoho'))
                                <span class="badge badge-light-success">Envoyée</span>
                            @else
                                <span class="badge badge-light-warning">En attente Zoho</span>
                            @endif
                        </div>
                        <div class="mt-3 text-muted fs-7">{{ $wave['companies'] }} societes &middot; {{ $wave['contacts'] }} contacts</div>
                        <div class="mt-1 text-gray-700 fs-8 text-break">{{ $wave['list_name'] }}</div>
                    </a>
                @empty
                    <div class="p-7 text-center text-muted">Aucune vague enregistree.</div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="col-xl-7">
        <div class="card h-100">
            <div class="card-header border-0 pt-5">
                <div class="card-title d-flex flex-column align-items-start">
                    <h3 class="fw-bolder m-0">Contacts de la vague</h3>
                    @if($selectedSummary)
                        <span class="text-muted fs-7 mt-1">{{ $selectedSummary['list_name'] }}</span>
                    @endif
                </div>
            </div>
            @if($selectedWave?->zoho_list_key)
                <div class="px-6 pb-4 d-flex align-items-center flex-wrap gap-3">
                    <span><span class="text-muted fs-8">Cle Zoho</span><code class="ms-2">{{ $selectedWave->zoho_list_key }}</code></span>
                    <a href="{{ $zohoCampaignsUrl }}" class="btn btn-sm btn-light-primary" target="_blank" rel="noopener">Ouvrir dans Zoho Campaigns</a>
                </div>
            @endif
            @if($selectedWave?->status === 'failed' && $selectedWave->canResyncZohoWave())
                <div class="px-6 pb-4">
                    <div class="alert alert-danger d-flex justify-content-between align-items-center flex-wrap gap-3 mb-0">
                        <div>
                            <div class="fw-bold">Synchronisation Zoho échouée</div>
                            <div class="fs-8">{{ $selectedWave->failure_reason }}</div>
                        </div>
                        @can('send campaigns')
                            <form method="POST" action="{{ route('admin.campaigns.retryZohoWave', [$model->id, $selectedWave->id]) }}"
                                  onsubmit="return confirm('La date de cette vague est passée. Si la synchronisation réussit, la campagne Zoho sera créée et envoyée immédiatement. Continuer ?')">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-danger">Relancer la vague Zoho</button>
                            </form>
                        @endcan
                    </div>
                </div>
            @endif
            <div class="card-body border-top p-0">
                @if($selectedWaveRecipients->isEmpty())
                    <div class="p-7 text-center text-muted">Selectionnez une vague pour voir ses contacts.</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-row-dashed align-middle gy-4 mb-0">
                            <thead><tr class="fw-bold text-muted bg-light"><th class="ps-6">Contact</th><th>Etape</th><th>Statut</th><th>Envois</th></tr></thead>
                            <tbody>
                            @foreach($selectedWaveRecipients as $recipient)
                                @php
                                    $enrollment = $waveEnrollments->get($recipient->contact_id);
                                    $latestSend = $enrollment?->stepSends?->sortByDesc('step_no')->first();
                                    $stepNo = $selectedWave?->sequenceStep?->step_no ?? $latestSend?->step_no ?? max(1, ((int) ($enrollment?->current_step ?? 0)) + 1);
                                    $statusCfg = $recipientStatuses[$recipient->status] ?? null;
                                    $enrollmentCfg = $enrollmentStatuses[$enrollment?->status ?? 'active'] ?? ['label' => 'Active', 'color' => 'secondary'];
                                @endphp
                                <tr>
                                    <td class="ps-6"><span class="fw-semibold">{{ $recipient->contact?->email ?? '--' }}</span><div class="text-muted fs-8">{{ $recipient->contact?->company?->name ?? '--' }}</div></td>
                                    <td><span class="badge badge-light-primary">Etape {{ $stepNo }}</span><div class="text-muted fs-8 mt-1">{{ $latestSend?->sent_at?->format('d/m/Y H:i') ?? '--' }}</div></td>
                                    <td>
                                        @if($statusCfg)
                                            <span class="badge badge-light-{{ $statusCfg['color'] }}">{{ $statusCfg['label'] }}</span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                        @if($recipient->status === 'skipped')
                                            <div class="text-muted fs-8 mt-1">{{ $skipReasons[$recipient->skip_reason] ?? 'Raison non précisée' }}</div>
                                        @endif
                                        <div class="mt-1"><span class="badge badge-light-{{ $enrollmentCfg['color'] }}">{{ $enrollmentCfg['label'] }}</span></div>
                                    </td>
                                    <td><div><span class="text-muted fs-8">Dernier :</span> {{ $enrollment?->last_sent_at?->format('d/m/Y H:i') ?? '--' }}</div><div><span class="text-muted fs-8">Prochain :</span> {{ $enrollment?->next_send_at?->format('d/m/Y H:i') ?? '--' }}</div></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

@if(($legacyWaves ?? collect())->isNotEmpty())
<div class="card mt-6">
    <div class="card-header border-0 pt-5"><h3 class="card-title fw-bolder m-0">Vagues historiques</h3></div>
    <div class="card-body border-top">
        <div class="alert bg-light-info text-info mb-5">Ces inscriptions precedent le suivi par vague et n'ont pas de liste Zoho dediee.</div>
        @foreach($legacyWaves as $date => $legacyEnrollments)
            <div class="border rounded p-4 mb-4">
                <div class="fw-bold mb-3">{{ \Carbon\Carbon::parse($date)->format('d/m/Y') }} &middot; {{ $legacyEnrollments->count() }} contacts</div>
                <div class="d-flex flex-wrap gap-2">
                    @foreach($legacyEnrollments as $legacyEnrollment)
                        <span class="badge badge-light-secondary">{{ $legacyEnrollment->contact?->company?->name ?? '--' }} &middot; {{ $legacyEnrollment->contact?->email ?? '--' }} &middot; Etape {{ max(1, (int) $legacyEnrollment->current_step + 1) }}</span>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</div>
@endif