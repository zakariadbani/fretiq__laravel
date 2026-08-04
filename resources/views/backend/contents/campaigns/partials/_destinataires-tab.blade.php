{{--
    Destinataires tab pane — contact-centric rollup (default) or run-scope (run_id=X).

    Expects:
        $recipients       — LengthAwarePaginator of CampaignRecipient (with contact.company loaded)
        $runs             — Collection of CampaignRun (for zero-runs empty state + run-scope banner)
        $model            — Campaign
        $recipientFilters — array{run, q, statut}   from CampaignController::recipientFilters()
        $chipCounts       — array{total,queued,sent,opened,clicked,replied,bounced,skipped}
--}}
@php
    $isRunScope = $recipientFilters['run'] !== null;

    // Status chip definitions (order matches UX spec).
    $statusesCfg = config('global.data.campaign_recipient_statuses', []);
    $skipReasons = config('global.data.campaign_recipient_skip_reasons', []);

    // URL builder — merges $overrides into current filter params, excludes recipients_page.
    $destUrl = function (array $overrides = []) use ($recipientFilters, $model) {
        $params = array_filter([
            'run_id' => isset($overrides['run_id'])
                ? $overrides['run_id']
                : ($recipientFilters['run']?->id ?? null),
            'q'      => isset($overrides['q'])
                ? $overrides['q']
                : ($recipientFilters['q'] !== '' ? $recipientFilters['q'] : null),
            'statut' => isset($overrides['statut'])
                ? $overrides['statut']
                : $recipientFilters['statut'],
        ], fn($v) => $v !== null && $v !== '');

        $qs = !empty($params) ? '?' . http_build_query($params) : '';

        return route('admin.campaigns.view', $model->id) . $qs . '#campaign_destinataires';
    };

    // Chip definitions.
    $chipDefs = [
        ['key' => 'total',   'label' => 'Total',    'color' => 'primary',   'count' => $chipCounts['total']   ?? 0],
        ['key' => 'queued',  'label' => 'En file',  'color' => 'secondary', 'count' => $chipCounts['queued']  ?? 0],
        ['key' => 'sent',    'label' => 'Envoyés',  'color' => 'info',      'count' => $chipCounts['sent']    ?? 0],
        ['key' => 'opened',  'label' => 'Ouverts',  'color' => 'success',   'count' => $chipCounts['opened']  ?? 0],
        ['key' => 'clicked', 'label' => 'Cliqués',  'color' => 'warning',   'count' => $chipCounts['clicked'] ?? 0],
        ['key' => 'replied', 'label' => 'Répondus', 'color' => 'success',   'count' => $chipCounts['replied'] ?? 0],
        ['key' => 'bounced', 'label' => 'Rebonds',  'color' => 'danger',    'count' => $chipCounts['bounced'] ?? 0],
        ['key' => 'skipped', 'label' => 'Ignorés',  'color' => 'dark',      'count' => $chipCounts['skipped'] ?? 0],
    ];
@endphp

<div class="card">
    <div class="card-header border-0 pt-5">
        <h3 class="card-title fw-bolder m-0">
            <i class="bi bi-envelope text-success fs-3 me-2"></i>
            Destinataires ({{ number_format($recipients->total()) }})
        </h3>

        @if($runs->count() > 0)
        {{-- Search form — GET, fragment preserved via action attr suffix --}}
        <div class="card-toolbar">
            <form method="GET"
                  action="{{ route('admin.campaigns.view', $model->id) }}#campaign_destinataires"
                  class="d-flex align-items-center gap-2">
                {{-- Hidden inputs carry current filters (NOT in action query string) --}}
                @if($recipientFilters['run'] !== null)
                    <input type="hidden" name="run_id" value="{{ $recipientFilters['run']->id }}">
                @endif
                @if($recipientFilters['statut'] !== null)
                    <input type="hidden" name="statut" value="{{ $recipientFilters['statut'] }}">
                @endif

                <input type="text"
                       name="q"
                       class="form-control form-control-sm form-control-solid w-200px"
                       placeholder="Email ou société…"
                       value="{{ $recipientFilters['q'] }}">

                @if($recipientFilters['q'] !== '')
                    <a href="{{ $destUrl(['q' => null]) }}"
                       class="btn btn-sm btn-light"
                       title="Effacer la recherche">
                        <i class="bi bi-x"></i>
                    </a>
                @endif
            </form>
        </div>
        @endif
    </div>

    @if($runs->count() === 0)
    {{-- Zero-runs empty state (verbatim text — tests assert) --}}
    <div class="card-body text-center py-8 text-muted">
        <i class="bi bi-envelope fs-2x mb-3 d-block"></i>
        Aucune exécution — aucun destinataire.
    </div>
    @else

    {{-- Run-scope banner --}}
    @if($isRunScope)
    <div class="mx-5 mb-0 mt-3 alert alert-dismissible bg-light-info d-flex align-items-center p-3 rounded"
         data-run-scope-banner>
        <i class="bi bi-funnel text-info fs-4 me-2"></i>
        <span class="fw-semibold text-info">
            Exécution du {{ $recipientFilters['run']->run_at?->format('d/m/Y H:i') ?? '—' }}
        </span>
        <a href="{{ $destUrl(['run_id' => null]) }}"
           class="btn btn-sm btn-icon btn-light-info ms-auto"
           title="Retour au rollup">
            <i class="bi bi-x fs-5"></i>
        </a>
    </div>
    @endif

    {{-- Chips row --}}
    <div class="mx-5 mt-4 d-flex flex-wrap gap-2">
        @foreach($chipDefs as $chip)
        @php
            $isActive = ($chip['key'] === 'total')
                ? ($recipientFilters['statut'] === null)
                : ($recipientFilters['statut'] === $chip['key']);

            // Hide skipped chip when count=0 and not currently active.
            $hideChip = ($chip['key'] === 'skipped' && $chip['count'] === 0 && !$isActive);
        @endphp
        @if(!$hideChip)
        <a href="{{ $chip['key'] === 'total' ? $destUrl(['statut' => null]) : ($isActive ? $destUrl(['statut' => null]) : $destUrl(['statut' => $chip['key']])) }}"
           class="btn btn-sm fw-semibold {{ $isActive ? 'btn-' . $chip['color'] : 'btn-light-' . $chip['color'] }}"
           data-chip="{{ $chip['key'] }}"
           data-count="{{ $chip['count'] }}">
            {{ $chip['label'] }}
            <span class="badge badge-light ms-1">{{ $chip['count'] }}</span>
        </a>
        @endif
        @endforeach
    </div>

    {{-- Table or empty states --}}
    @if($recipients->total() === 0 && ($recipientFilters['statut'] !== null || $recipientFilters['q'] !== ''))
    {{-- Filtered-empty state --}}
    <div class="card-body text-center py-8 text-muted">
        <i class="bi bi-funnel fs-2x mb-3 d-block"></i>
        Aucun résultat pour ces filtres.
        <div class="mt-2">
            <a href="{{ $destUrl(['statut' => null, 'q' => null]) }}" class="btn btn-sm btn-light-primary">
                Réinitialiser les filtres
            </a>
        </div>
    </div>
    @elseif($recipients->total() === 0)
    {{-- Bare empty --}}
    <div class="card-body text-center py-8 text-muted">
        <i class="bi bi-envelope fs-2x mb-3 d-block"></i>
        Aucun destinataire enregistré.
    </div>
    @else
    @if($recipientStepReport !== null)
    <div class="card-body border-top pb-2 mt-4">
        <div class="alert bg-light-info d-flex align-items-start p-3 mb-0 rounded">
            <i class="bi bi-info-circle text-info fs-4 me-3 mt-1"></i>
            <div class="text-gray-700 fs-7">
                Les filtres de statut s&rsquo;appliquent aux contacts selon tout leur historique. Les totaux d&rsquo;&eacute;tape cumulent toutes les ex&eacute;cutions envoy&eacute;es.
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-4 mb-0"
                   style="min-width: 100%; width: max-content;"
                   data-recipient-step-matrix>
                <thead>
                    <tr class="fw-bold text-muted bg-light">
                        <th class="ps-7 bg-light"
                            style="position: sticky; left: 0; z-index: 2; min-width: 240px;">
                            Contact
                        </th>
                        @foreach($recipientStepReport['steps'] as $step)
                            @php
                                $summary = $recipientStepReport['summaries'][$step->id] ?? [];
                                $stepSubject = $step->subject ?: $step->template?->subject ?: 'Sans objet';
                            @endphp
                            <th class="min-w-250px"
                                data-step-summary="{{ $step->step_no }}"
                                data-step="{{ $step->step_no }}">
                                <div class="text-gray-900">&Eacute;tape {{ $step->step_no }}</div>
                                <div class="text-muted fs-7 fw-normal mb-2">{{ $stepSubject }}</div>
                                <div class="d-flex flex-wrap gap-1 fs-8 fw-normal">
                                    <span class="badge badge-light-primary">{{ number_format($summary['sent'] ?? 0) }} envoy&eacute;s</span>
                                    <span class="badge badge-light-info">{{ number_format($summary['delivered'] ?? 0) }} d&eacute;livr&eacute;s</span>
                                    <span class="badge badge-light-success">{{ number_format($summary['opened'] ?? 0) }} ouverts</span>
                                    <span class="badge badge-light-warning">{{ number_format($summary['clicked'] ?? 0) }} clics</span>
                                    <span class="badge badge-light-danger">{{ number_format($summary['bounced'] ?? 0) }} rebonds</span>
                                </div>
                            </th>
                        @endforeach
                        @can('create demandes')
                            <th class="text-end pe-7 min-w-150px">Action</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @foreach($recipients as $row)
                        @php
                            $isReplied = (bool) $row->has_replied;
                        @endphp
                        <tr data-recipient-email="{{ $row->contact?->email ?? '' }}">
                            <td class="ps-7 bg-body"
                                style="position: sticky; left: 0; z-index: 1; min-width: 240px;">
                                <span class="fw-semibold">
                                    @if($row->contact?->email)
                                        {{ $row->contact->email }}
                                    @else
                                        &mdash;
                                    @endif
                                </span>
                                @if($row->contact?->company)
                                    <div class="text-muted fs-7">{{ $row->contact->company->name }}</div>
                                @endif
                            </td>
                            @foreach($recipientStepReport['steps'] as $step)
                                @include('backend.contents.campaigns.partials._recipient-step-cell', [
                                    'recipient' => $recipientStepReport['cells'][$row->contact_id][$step->id] ?? null,
                                    'step' => $step,
                                ])
                            @endforeach
                            @can('create demandes')
                                <td class="text-end pe-7">
                                    @if(!$isReplied)
                                        <form method="POST"
                                              action="{{ route('admin.campaigns.markReplied', [$model->id, $row->id]) }}"
                                              onsubmit="return confirm('Marquer ce contact comme r&eacute;pondu et cr&eacute;er une demande ?');">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-light-success">
                                                <i class="bi bi-reply me-1"></i>
                                                R&eacute;pondu &rarr; Demande
                                            </button>
                                        </form>
                                    @else
                                        <span class="text-muted fs-7">D&eacute;j&agrave; r&eacute;pondu</span>
                                    @endif
                                </td>
                            @endcan
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @else
    <div class="card-body border-top p-0 mt-4">
        <div class="table-responsive">
            <table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-4 mb-0" data-run-recipient-table>
                <thead>
                    <tr class="fw-bold text-muted bg-light">
                        <th class="ps-7">Contact</th>
                        @if(!$isRunScope)
                        <th>Envois</th>
                        @endif
                        <th>{{ $isRunScope ? 'Statut' : 'Dernier statut' }}</th>
                        <th>Envoyé le</th>
                        <th>Ouvert le</th>
                        <th>Cliqué le</th>
                        @can('create demandes')
                        <th class="text-end pe-7">Action</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @foreach($recipients as $row)
                    @php
                        $statusKey = $row->status ?? '';
                        $statusCfg = $statusesCfg[$statusKey] ?? null;

                        // Scope-switched columns.
                        $sentAt    = $isRunScope ? $row->sent_at    : $row->max_sent_at;
                        $openedAt  = $isRunScope ? $row->opened_at  : $row->max_opened_at;
                        $clickedAt = $isRunScope ? $row->clicked_at : $row->max_clicked_at;

                        // "Already replied" display decision:
                        // run scope = this row's status; rollup = has_replied (any run).
                        $isReplied = $isRunScope
                            ? ($row->status === 'replied')
                            : (bool) $row->has_replied;
                    @endphp
                    <tr>
                        <td class="ps-7">
                            <span class="fw-semibold">
                                {{ $row->contact?->email ? e($row->contact->email) : '—' }}
                            </span>
                            @if($row->contact?->company)
                            <div class="text-muted fs-7">{{ $row->contact->company->name }}</div>
                            @endif
                        </td>
                        @if(!$isRunScope)
                        <td>
                            <span class="badge badge-light-secondary">{{ $row->envois ?? 1 }}</span>
                        </td>
                        @endif
                        <td>
                            @if($statusCfg)
                                <span class="badge badge-light-{{ $statusCfg['color'] }}">{{ $statusCfg['label'] }}</span>
                                @if($statusKey === 'skipped')
                                    <div class="text-muted fs-8 mt-1">{{ $skipReasons[$row->skip_reason] ?? 'Raison non précisée' }}</div>
                                @endif
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>{{ $sentAt?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td>{{ $openedAt?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td>{{ $clickedAt?->format('d/m/Y H:i') ?? '—' }}</td>
                        @can('create demandes')
                        <td class="text-end pe-7">
                            @if(!$isReplied)
                            <form method="POST"
                                  action="{{ route('admin.campaigns.markReplied', [$model->id, $row->id]) }}"
                                  onsubmit="return confirm('Marquer ce contact comme répondu et créer une demande ?');">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-light-success" title="Marquer répondu → Demande">
                                    <i class="bi bi-reply me-1"></i>
                                    Répondu → Demande
                                </button>
                            </form>
                            @else
                                <span class="text-muted fs-7">Déjà répondu</span>
                            @endif
                        </td>
                        @endcan
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    @if($recipients->hasPages())
    <div class="card-footer d-flex justify-content-end py-4">
        {{ $recipients->fragment('campaign_destinataires')->links('pagination::bootstrap-5') }}
    </div>
    @endif

    @endif {{-- table or empty --}}
    @endif {{-- zero-runs --}}
</div>
