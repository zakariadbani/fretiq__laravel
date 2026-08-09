<x-default-layout>
    @section('title', 'Marketing & commercial')

    @section('breadcrumbs')
        <x-crud.breadcrumb :items="[['label' => 'Marketing & commercial']]" />
    @endsection

    @php
        $options = $dashboard['filter_options'] ?? [];
        $filters = $dashboard['filters'] ?? [];
        $kpis = $dashboard['kpis'] ?? [];
        $campaign = $dashboard['campaign']['counts'] ?? [];
        $previousCampaign = $dashboard['comparison']['campaign']['counts'] ?? [];
        $quotes = $kpis['quotes'] ?? [];
        $previousQuotes = $dashboard['comparison']['quotes'] ?? [];
        $outcomes = $kpis['deal_outcomes'] ?? [];
        $previousOutcomes = $dashboard['comparison']['deal_outcomes'] ?? [];
        $pipeline = $kpis['pipeline'] ?? [];
        $zohoFunnel = $dashboard['funnels']['zoho'] ?? [];
        $money = static fn ($value, $currency): string => is_numeric($value) ? number_format((float) $value, 2, ',', ' ').' '.e((string) $currency) : '—';
        $rate = static fn ($value): string => is_numeric($value) ? number_format((float) $value, 1, ',', ' ').' %' : 'non calculable';
        $selects = [
            'commercial' => ['label' => 'Commercial', 'options' => 'commercials'],
            'source' => ['label' => 'Source', 'options' => 'sources'],
            'campaign' => ['label' => 'Campagne', 'options' => 'campaigns'],
            'country' => ['label' => 'Pays', 'options' => 'countries'],
            'sector' => ['label' => 'Secteur', 'options' => 'sectors'],
            'transport' => ['label' => 'Transport', 'options' => 'transports'],
            'client_type' => ['label' => 'Type client', 'options' => 'client_types'],
            'lead_source' => ['label' => 'Source Zoho', 'options' => 'lead_sources'],
            'currency' => ['label' => 'Devise', 'options' => 'currencies'],
        ];
        $periodLabels = ['7d' => '7 jours', '30d' => '30 jours', '90d' => '90 jours', 'qtd' => 'Trimestre en cours', 'ytd' => 'Année en cours', 'custom' => 'Personnalisée'];
    @endphp

    <section class="card border-0 mb-6 marketing-hero overflow-hidden" data-testid="marketing-dashboard-shell">
        <div class="card-body p-6 p-lg-8 position-relative">
            <div class="marketing-orb marketing-orb-one"></div><div class="marketing-orb marketing-orb-two"></div>
            <div class="position-relative d-flex flex-column flex-xl-row align-items-xl-center justify-content-between gap-5">
                <div>
                    <div class="text-white-50 text-uppercase fw-semibold fs-8 mb-2">Vue de pilotage</div>
                    <h1 class="text-white fw-bolder fs-2x mb-2">Pilotage marketing & commercial</h1>
                    <p class="text-white-75 mb-0">Prospection Fretiq et miroir Zoho CRM, en lecture seule — fuseau Europe/Paris.</p>
                </div>
                <div class="alert alert-light-primary mb-0 py-3 px-4 marketing-attribution-note" role="note">
                    <i class="bi bi-shield-check me-2" aria-hidden="true"></i>Interactions rapprochées sans attribution causale de chiffre d’affaires.
                </div>
            </div>
        </div>
    </section>

    <section class="card mb-6">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.dashboard.marketing') }}" class="row g-3" aria-label="Filtres du tableau de bord marketing">
                <div class="col-12 col-md-4 col-xl-2">
                    <label class="form-label" for="marketing-period">Période</label>
                    <select id="marketing-period" name="period" class="form-select form-select-solid">
                        @foreach($periodLabels as $value => $label)<option value="{{ $value }}" @selected($controls['period'] === $value)>{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div class="col-6 col-md-4 col-xl-2 marketing-custom-date">
                    <label class="form-label" for="marketing-from">Du</label><input id="marketing-from" name="from" type="date" class="form-control form-control-solid" value="{{ $controls['from'] }}">
                </div>
                <div class="col-6 col-md-4 col-xl-2 marketing-custom-date">
                    <label class="form-label" for="marketing-to">Au</label><input id="marketing-to" name="to" type="date" class="form-control form-control-solid" value="{{ $controls['to'] }}">
                </div>
                @foreach($selects as $name => $definition)
                    <div class="col-6 col-md-4 col-xl-2">
                        <label class="form-label" for="marketing-{{ $name }}">{{ $definition['label'] }}</label>
                        <select id="marketing-{{ $name }}" name="{{ $name }}" class="form-select form-select-solid">
                            <option value="">Tous</option>
                            @foreach($options[$definition['options']] ?? [] as $option)<option value="{{ $option['value'] }}" @selected(($filters[$name] ?? '') === $option['value'])>{{ $option['label'] }}</option>@endforeach
                        </select>
                    </div>
                @endforeach
                <div class="col-12 d-flex flex-wrap gap-3 pt-2">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Appliquer</button>
                    <a href="{{ route('admin.dashboard.marketing') }}" class="btn btn-light">Réinitialiser</a>
                </div>
            </form>
        </div>
    </section>

    @if(($dashboard['meta']['stale_or_unavailable'] ?? false) === true)
        <div class="alert alert-warning d-flex align-items-center mb-6" role="alert"><i class="bi bi-exclamation-triangle fs-2 me-3" aria-hidden="true"></i><div>Le miroir Zoho n’est pas encore disponible ou son schéma est incomplet. Les indicateurs CRM seront affichés dès que la synchronisation contrôlée aura produit des données vérifiées.</div></div>
    @endif
    @if(($dashboard['scope']['mapping_required'] ?? false) === true)
        <div class="alert alert-warning d-flex align-items-center mb-6" role="alert"><i class="bi bi-person-lock fs-2 me-3" aria-hidden="true"></i><div>Votre portefeuille Zoho doit être confirmé par un administrateur avant de pouvoir afficher vos données CRM.</div></div>
    @endif
    @if(($dashboard['scope']['selection_invalid'] ?? false) === true)
        <div class="alert alert-info mb-6" role="status">Le commercial demandé n’est pas disponible. Les indicateurs sont volontairement vides.</div>
    @endif
    @foreach(($dashboard['filter_applicability'] ?? []) as $filter => $applicability)
        @if(($applicability['ignored_by'] ?? []) !== [])
            <div class="alert alert-light-info py-3 mb-4" role="status"><strong>Filtre {{ $filter }} :</strong> non applicable aux panneaux {{ implode(', ', $applicability['ignored_by']) }}@if(($applicability['partially_applies_to'] ?? []) !== []) ; application partielle signalée pour {{ implode(', ', array_keys($applicability['partially_applies_to'])) }}@endif.</div>
        @endif
    @endforeach

    <div class="row g-5 mb-6">
        @foreach([
            ['Entreprises découvertes', $kpis['discovered_companies'] ?? 0, 'bi-buildings', 'primary'],
            ['Contacts découverts', $kpis['discovered_contacts'] ?? 0, 'bi-people', 'info'],
            ['Nouveaux leads Zoho', $kpis['new_leads'] ?? 0, 'bi-person-plus', 'warning'],
            ['Nouveaux comptes Zoho', $kpis['new_accounts'] ?? 0, 'bi-building-add', 'primary'],
            ['Nouveaux contacts Zoho', $kpis['new_contacts'] ?? 0, 'bi-person-vcard', 'info'],
            ['Opportunités créées', $kpis['deals_created'] ?? 0, 'bi-briefcase', 'success'],
            ['Devis créés', $kpis['quotes_created'] ?? 0, 'bi-file-earmark-text', 'success'],
        ] as [$label, $value, $icon, $color])
            <div class="col-sm-6 col-xl-3"><article class="card h-100 marketing-kpi"><div class="card-body d-flex align-items-center gap-4"><span class="marketing-icon bg-light-{{ $color }} text-{{ $color }}"><i class="bi {{ $icon }} fs-2" aria-hidden="true"></i></span><div><div class="text-muted fw-semibold fs-7">{{ $label }}</div><div class="fs-2 fw-bolder">{{ number_format((int) $value, 0, ',', ' ') }}</div></div></div></article></div>
        @endforeach
    </div>

    <section class="card mb-6"><div class="card-header"><h2 class="card-title fw-bolder">Comparaison avec la période précédente</h2></div><div class="card-body"><div class="row g-4">@foreach(['discovered_companies' => 'Entreprises découvertes', 'discovered_contacts' => 'Contacts découverts', 'new_leads' => 'Leads', 'new_accounts' => 'Comptes', 'new_contacts' => 'Contacts Zoho', 'deals_created' => 'Opportunités', 'quotes_created' => 'Devis'] as $key => $label)<div class="col-6 col-md-3"><div class="text-muted fs-8">{{ $label }}</div><div class="fw-bolder fs-4">{{ number_format((int) ($kpis[$key] ?? 0), 0, ',', ' ') }}</div><div class="small text-muted">précédent : {{ number_format((int) ($dashboard['comparison'][$key] ?? 0), 0, ',', ' ') }}</div></div>@endforeach</div></div></section>

    @if($drilldowns !== [])
    <nav class="card mb-6" aria-label="Accès aux explorateurs CRM">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <span class="fw-bold me-2">Explorer le CRM :</span>
            @foreach(['leads' => 'Leads', 'accounts' => 'Comptes', 'contacts' => 'Contacts', 'deals' => 'Opportunités', 'quotes' => 'Devis'] as $module => $label)
                <a href="{{ $drilldowns[$module] }}" class="btn btn-sm btn-light-primary">{{ $label }}</a>
            @endforeach
        </div>
    </nav>
    @endif

    <div class="row g-5 mb-6">
        <div class="col-xl-6"><section class="card h-100"><div class="card-header"><h2 class="card-title fw-bolder">Entonnoir Fretiq</h2></div><div class="card-body">
            <div class="table-responsive"><table class="table align-middle table-row-dashed mb-3"><thead><tr><th>Étape</th><th class="text-end">Période actuelle</th><th class="text-end">Période précédente</th></tr></thead><tbody>
                @foreach(['sent' => 'Envoyés', 'delivered' => 'Délivrés', 'opened' => 'Ouverts', 'clicked' => 'Cliqués', 'replied' => 'Réponses', 'demandes' => 'Demandes observées'] as $key => $label)
                    <tr><td>{{ $label }}</td><td class="text-end"><span data-testid="campaign-{{ $key }}-current">{{ number_format((int) ($campaign[$key] ?? 0), 0, ',', ' ') }}</span></td><td class="text-end"><span data-testid="campaign-{{ $key }}-previous">{{ number_format((int) ($previousCampaign[$key] ?? 0), 0, ',', ' ') }}</span></td></tr>
                @endforeach
            </tbody></table></div>
            <div class="d-flex flex-wrap gap-3 small text-muted">
                <span>Engagement : <strong data-testid="campaign-engagement-rate-current">{{ $rate($campaign['engagement_rate'] ?? null) }}</strong> (précédent : <span data-testid="campaign-engagement-rate-previous">{{ $rate($previousCampaign['engagement_rate'] ?? null) }}</span>)</span>
                <span>Conversion en demande observée : <strong data-testid="campaign-demande-rate-current">{{ $rate($campaign['demande_conversion_rate'] ?? null) }}</strong> (précédent : <span data-testid="campaign-demande-rate-previous">{{ $rate($previousCampaign['demande_conversion_rate'] ?? null) }}</span>)</span>
            </div>
        </div></section></div>
        <div class="col-xl-6"><section class="card h-100"><div class="card-header d-flex align-items-center"><h2 class="card-title fw-bolder">Entonnoir commercial Zoho</h2>@isset($drilldowns['deals'])<a href="{{ $drilldowns['deals'] }}" class="btn btn-sm btn-light-primary ms-auto">Voir les opportunités</a>@endisset</div><div class="card-body">
            @foreach(['Leads' => $kpis['new_leads'] ?? 0, 'Opportunités créées' => $kpis['deals_created'] ?? 0, 'Devis créés' => $kpis['quotes_created'] ?? 0, 'Affaires gagnées' => $outcomes['won'] ?? 0, 'Affaires perdues' => $outcomes['lost'] ?? 0] as $label => $count)<div class="d-flex justify-content-between align-items-center py-3 border-bottom"><span>{{ $label }}</span><span class="badge badge-light-success fs-7">{{ number_format((int) $count, 0, ',', ' ') }}</span></div>@endforeach
            <div class="table-responsive mt-4"><table class="table table-sm align-middle mb-2"><thead><tr><th>Résultat</th><th class="text-end">Actuel</th><th class="text-end">Précédent</th></tr></thead><tbody>
                <tr><td>Opportunités gagnées</td><td class="text-end"><span data-testid="deal-won-current">{{ (int) ($outcomes['won'] ?? 0) }}</span></td><td class="text-end"><span data-testid="deal-won-previous">{{ (int) ($previousOutcomes['won'] ?? 0) }}</span></td></tr>
                <tr><td>Opportunités perdues</td><td class="text-end"><span data-testid="deal-lost-current">{{ (int) ($outcomes['lost'] ?? 0) }}</span></td><td class="text-end"><span data-testid="deal-lost-previous">{{ (int) ($previousOutcomes['lost'] ?? 0) }}</span></td></tr>
                <tr><td>Devis gagnés</td><td class="text-end"><span data-testid="quote-won-current">{{ (int) ($quotes['won_count'] ?? 0) }}</span></td><td class="text-end"><span data-testid="quote-won-previous">{{ (int) ($previousQuotes['won_count'] ?? 0) }}</span></td></tr>
                <tr><td>Devis perdus</td><td class="text-end"><span data-testid="quote-lost-current">{{ (int) ($quotes['lost_count'] ?? 0) }}</span></td><td class="text-end"><span data-testid="quote-lost-previous">{{ (int) ($previousQuotes['lost_count'] ?? 0) }}</span></td></tr>
            </tbody></table></div>
            <p class="text-muted small mt-3 mb-0">Taux de gain des devis : <strong data-testid="quote-win-rate-current">{{ $rate($quotes['win_rate'] ?? null) }}</strong> · précédent : <span data-testid="quote-win-rate-previous">{{ $rate($previousQuotes['win_rate'] ?? null) }}</span> · délai médian de décision : {{ $quotes['median_decision_days'] ?? '—' }} jours.</p>
        </div></section></div>
    </div>

    <section class="card mb-6"><div class="card-header"><h2 class="card-title fw-bolder">Qualification, étapes et statuts Zoho</h2></div><div class="card-body"><div class="row g-5">
        @foreach(['lead_qualification' => 'Qualification actuelle des nouveaux leads', 'deal_stages' => 'Étapes des opportunités créées', 'quote_statuses' => 'Statuts des devis créés'] as $key => $label)
            <div class="col-md-4"><h3 class="fs-6 fw-bold">{{ $label }}</h3>@forelse(($zohoFunnel[$key] ?? []) as $entry => $count)<div class="d-flex justify-content-between small border-bottom py-2"><span>{{ $entry }}</span><span class="badge badge-light">{{ number_format((int) $count, 0, ',', ' ') }}</span></div>@empty <div class="text-muted small">Aucune donnée</div>@endforelse</div>
        @endforeach
    </div><hr class="my-5"><h3 class="fs-6 fw-bold mb-3">Transitions de statut observées</h3><div class="row g-5">
        <div class="col-md-6"><div class="text-muted fs-8 text-uppercase mb-2">Opportunités</div>@forelse(($zohoFunnel['transitions']['deals'] ?? []) as $entry => $count)@php($transitionKey = \Illuminate\Support\Str::slug((string) $entry))<div class="d-flex justify-content-between small border-bottom py-2" data-testid="deal-transition-row-{{ $transitionKey }}"><span>{{ $entry }}</span><span data-testid="deal-transition-{{ $transitionKey }}">{{ $count }}</span></div>@empty <div class="text-muted small">Aucune transition</div>@endforelse</div>
        <div class="col-md-6"><div class="text-muted fs-8 text-uppercase mb-2">Devis</div>@forelse(($zohoFunnel['transitions']['quotes'] ?? []) as $entry => $count)@php($transitionKey = \Illuminate\Support\Str::slug((string) $entry))<div class="d-flex justify-content-between small border-bottom py-2" data-testid="quote-transition-row-{{ $transitionKey }}"><span>{{ $entry }}</span><span data-testid="quote-transition-{{ $transitionKey }}">{{ $count }}</span></div>@empty <div class="text-muted small">Aucune transition</div>@endforelse</div>
    </div></div></section>

    <div class="row g-5 mb-6">
        <div class="col-xl-7"><section class="card h-100"><div class="card-header"><h2 class="card-title fw-bolder">Tendances de la période</h2></div><div class="card-body table-responsive"><table class="table align-middle table-row-dashed"><thead><tr><th>Indicateur</th><th>Événements observés</th></tr></thead><tbody>@foreach(['leads' => 'Leads', 'deals' => 'Opportunités', 'quotes' => 'Devis', 'wins' => 'Gains', 'demandes' => 'Demandes'] as $key => $label)<tr><td>{{ $label }}</td><td>@forelse(($dashboard['trends'][$key] ?? []) as $date => $count)<span class="badge badge-light me-1 mb-1">{{ $date }} : {{ $count }}</span>@empty <span class="text-muted">Aucune donnée</span>@endforelse</td></tr>@endforeach</tbody></table></div></section></div>
        <div class="col-xl-5"><section class="card h-100"><div class="card-header"><h2 class="card-title fw-bolder">Valeurs par devise native</h2></div><div class="card-body"><p class="text-muted small">Aucune conversion ni total multi-devise n’est affiché. Une valeur n’est couverte que si son montant et sa devise native sont tous deux renseignés.</p><div class="small mb-4"><div>Pipeline actif : {{ $pipeline['amount_known_count'] ?? 0 }}/{{ $pipeline['count'] ?? 0 }} couverts · montant manquant <span data-testid="pipeline-amount-missing-value">{{ (int) ($pipeline['amount_missing_value_count'] ?? 0) }}</span> · devise manquante <span data-testid="pipeline-amount-missing-currency">{{ (int) ($pipeline['amount_missing_currency_count'] ?? 0) }}</span></div><div>Pipeline pondéré : {{ $pipeline['weighted_known_count'] ?? 0 }}/{{ $pipeline['count'] ?? 0 }} couverts · montant manquant <span data-testid="pipeline-weighted-missing-value">{{ (int) ($pipeline['weighted_missing_value_count'] ?? 0) }}</span> · devise manquante <span data-testid="pipeline-weighted-missing-currency">{{ (int) ($pipeline['weighted_missing_currency_count'] ?? 0) }}</span></div></div>@forelse(($pipeline['amount_by_currency'] ?? []) as $currency => $value)<div class="d-flex justify-content-between py-2 border-bottom"><span>Pipeline actif · {{ $currency }}</span><strong>{{ $money($value, $currency) }}</strong></div>@empty <div class="text-muted">Aucune valeur de pipeline exploitable.</div>@endforelse @foreach(($pipeline['weighted_by_currency'] ?? []) as $currency => $value)<div class="d-flex justify-content-between py-2 border-bottom"><span>Pipeline pondéré · {{ $currency }}</span><strong>{{ $money($value, $currency) }}</strong></div>@endforeach @foreach(($outcomes['won_value_by_currency'] ?? []) as $currency => $value)<div class="d-flex justify-content-between py-2 border-bottom"><span>Gagné · {{ $currency }}</span><strong>{{ $money($value, $currency) }}</strong></div>@endforeach @foreach(($quotes['value_by_currency'] ?? []) as $currency => $value)<div class="d-flex justify-content-between py-2 border-bottom"><span>Devis · {{ $currency }}</span><strong>{{ $money($value, $currency) }}</strong></div>@endforeach</div></section></div>
    </div>

    <div class="row g-5 mb-6">
        <div class="col-xl-7"><section class="card h-100"><div class="card-header"><h2 class="card-title fw-bolder">Performance par segment</h2></div><div class="card-body"><div class="row g-4">@foreach(['commercial' => 'Commercial', 'source' => 'Source', 'sector' => 'Secteur', 'country' => 'Pays', 'transport' => 'Transport', 'lane' => 'Ligne'] as $key => $label)<div class="col-md-6"><h3 class="fs-6 fw-bold">{{ $label }}</h3>@forelse(($dashboard['breakdowns'][$key] ?? []) as $entry => $count) @if(is_array($count))<div class="d-flex justify-content-between small border-bottom py-1"><span>{{ $count['label'] }}</span><span>{{ $count['count'] }}</span></div>@else <div class="d-flex justify-content-between small border-bottom py-1"><span>{{ $entry }}</span><span>{{ $count }}</span></div>@endif @empty <div class="text-muted small">Aucune donnée</div>@endforelse</div>@endforeach</div></div></section></div>
        <div class="col-xl-5"><section class="card h-100"><div class="card-header"><h2 class="card-title fw-bolder">À surveiller</h2></div><div class="card-body"><ul class="list-unstyled mb-0 marketing-attention-list">@foreach(['quotes_expiring_7_days' => 'Devis expirant sous 7 jours', 'open_leads_without_activity_14_days' => 'Leads ouverts sans activité depuis 14 jours', 'open_deals_without_activity_14_days' => 'Opportunités ouvertes sans activité depuis 14 jours', 'sync_failures' => 'Anomalies de synchronisation'] as $key => $label)<li><span>{{ $label }}</span><span class="badge badge-light-warning">{{ number_format((int) ($dashboard['attention'][$key] ?? 0), 0, ',', ' ') }}</span></li>@endforeach @foreach(($dashboard['attention']['unassigned'] ?? []) as $key => $count)<li><span>{{ ucfirst($key) }} non attribués</span><span class="badge badge-light-danger">{{ number_format((int) $count, 0, ',', ' ') }}</span></li>@endforeach @foreach(($dashboard['attention']['stale_modules'] ?? []) as $module => $state)<li><span>Module {{ $module }} obsolète</span><span class="badge badge-light-danger">{{ implode(' / ', $state) }}</span></li>@endforeach</ul>@isset($drilldowns['quotes'])<a class="btn btn-sm btn-light-primary mt-5" href="{{ $drilldowns['quotes'] }}">Explorer les devis</a>@endisset</div></section></div>
    </div>

    <section class="card mb-6"><div class="card-header"><h2 class="card-title fw-bolder">Interactions Fretiq rapprochées</h2></div><div class="card-body"><p class="text-muted">{{ $dashboard['matched_touches']['label'] ?? 'Interactions observées — sans attribution causale' }}. Cette vue ne crédite jamais une campagne d’un devis, d’une affaire ou d’un revenu.</p><div class="d-flex flex-wrap gap-3"><span class="badge badge-light-primary">{{ number_format((int) ($dashboard['matched_touches']['count'] ?? 0), 0, ',', ' ') }} interactions observées</span><span class="badge badge-light-info">{{ number_format((int) ($dashboard['identity_coverage']['coverage']['matched'] ?? 0), 0, ',', ' ') }} rapprochements déterministes</span>@if(($dashboard['matched_touches']['items_truncated'] ?? false) === true)<span class="text-muted small">Liste limitée aux 100 événements les plus récents.</span>@endif</div><div class="mt-4">@forelse(($dashboard['matched_touches']['items'] ?? []) as $touch)<span class="badge badge-light me-1 mb-1">{{ $touch['event_type'] }} · {{ $touch['event_at'] }}</span>@empty <span class="text-muted">Aucune interaction déterministe sur cette période.</span>@endforelse</div></div></section>

    @push('styles')
        <style>
            .marketing-hero { background: linear-gradient(125deg, #102744 0%, #175a7f 55%, #10a7a0 100%); }
            .marketing-hero .text-white-75 { color: rgba(255,255,255,.78); }
            .marketing-orb { position:absolute; border-radius:50%; background:rgba(255,255,255,.08); pointer-events:none; }
            .marketing-orb-one { width:250px; height:250px; right:7%; top:-145px; } .marketing-orb-two { width:150px; height:150px; right:29%; bottom:-105px; }
            .marketing-attribution-note { max-width:360px; } .marketing-kpi { border:1px solid var(--bs-gray-200); }
            .marketing-icon { width:46px; height:46px; border-radius:12px; display:inline-flex; align-items:center; justify-content:center; }
            .marketing-attention-list li { display:flex; justify-content:space-between; gap:1rem; padding:.7rem 0; border-bottom:1px dashed var(--bs-gray-300); }
            @media (max-width: 767.98px) { .marketing-hero .card-body { min-height:240px; } .marketing-attribution-note { max-width:none; } }
        </style>
    @endpush
</x-default-layout>
