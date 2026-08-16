@php
    $primaryCandidate = $review['primary_candidate'];
    $domainChoices = collect($review['alternatives']);
    if ($review['primary_action'] !== 'approve_domain' && $primaryCandidate !== null) {
        $domainChoices = collect([$primaryCandidate])->concat($domainChoices);
    }
    $selectableChoices = $domainChoices->filter(fn (array $candidate): bool => $candidate['selectable'])->values();
@endphp

<article
    class="prospect-review-company"
    data-review-company-card="{{ $item->id }}"
    data-review-company-detail="{{ $item->id }}"
>
    <header class="d-flex flex-column flex-md-row align-items-md-start justify-content-between gap-3 mb-5">
        <div class="min-w-0">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <span class="badge badge-light-{{ $review['issue']['color'] }}">
                    {{ $review['issue']['kind'] === 'interrupted' ? 'Traitement interrompu' : 'Décision requise' }}
                </span>
                <span class="text-muted fs-8">{{ $item->batch?->name }}</span>
            </div>
            <h2 class="fs-1 mb-2 text-break">{{ $item->company_name }}</h2>
            <div class="text-muted">
                <i class="bi bi-geo-alt me-1" aria-hidden="true"></i>
                {{ collect([$item->city, $item->country])->filter()->join(', ') ?: 'Localisation non précisée' }}
            </div>
        </div>
        <a class="btn btn-sm btn-light" href="{{ route('admin.prospect_batches.view', ['id' => $item->prospect_batch_id]) }}">
            Voir le lot <i class="bi bi-arrow-up-right ms-1" aria-hidden="true"></i>
        </a>
    </header>

    <section aria-labelledby="company-checks-{{ $item->id }}" class="mb-5">
        <div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-3">
            <div>
                <h3 id="company-checks-{{ $item->id }}" class="fs-5 mb-1">Ce que Fretiq a vérifié</h3>
                <p class="text-muted fs-8 mb-0">Vert : terminé · Orange : attention · Rouge : action nécessaire</p>
            </div>
            <span class="badge badge-light-warning">{{ $review['attention_count'] }} point(s) à examiner</span>
        </div>

        <div class="prospect-review-checks">
            @foreach($review['checks'] as $check)
                <div class="prospect-review-check" data-review-check="{{ \Illuminate\Support\Str::slug($check['label']) }}">
                    <span class="prospect-review-check-icon bg-light-{{ $check['tone'] }} text-{{ $check['tone'] }} mb-3">
                        <i class="bi {{ $check['icon'] }}" aria-hidden="true"></i>
                    </span>
                    <div class="fw-semibold text-gray-900">{{ $check['label'] }}</div>
                    <div class="text-{{ $check['tone'] }} fs-8 mt-1">{{ $check['detail'] }}</div>
                </div>
            @endforeach
        </div>
    </section>

    <div class="notice d-flex bg-light-{{ $review['issue']['color'] }} rounded border border-{{ $review['issue']['color'] }} border-dashed p-4 mb-5">
        <i class="bi {{ $review['issue']['kind'] === 'interrupted' ? 'bi-x-octagon' : 'bi-exclamation-circle' }} fs-2 text-{{ $review['issue']['color'] }} me-3" aria-hidden="true"></i>
        <div>
            <div class="fw-bold">{{ $review['issue']['label'] }}</div>
            <div class="text-muted">{{ $review['issue']['description'] }}</div>
        </div>
    </div>

    <div class="prospect-review-decision-grid mb-5">
        <div class="border rounded p-4 bg-light-success">
            <div class="text-success fw-bold fs-8 text-uppercase mb-2">Déjà correct</div>
            <div class="fw-semibold">{{ $review['correct'] }}</div>
        </div>
        <div class="border rounded p-4 bg-light-warning">
            <div class="text-warning fw-bold fs-8 text-uppercase mb-2">À compléter</div>
            <div class="fw-semibold">{{ $review['missing'] }}</div>
        </div>
    </div>

    <section class="mb-5" aria-labelledby="recommended-action-{{ $item->id }}" data-review-company-actions="{{ $item->id }}">
        @unless($review['primary_action'] === 'retry' && ($review['retry_blocked'] || $review['contact_collection_resume'] || ($review['enrichment_resume'] ?? false)))
            <div class="mb-3">
                <div class="text-primary fw-bold fs-8 text-uppercase mb-1">Prochaine action recommandée</div>
                <h3 id="recommended-action-{{ $item->id }}" class="fs-3 mb-1">{{ $review['primary_label'] }}</h3>
                <p class="text-muted mb-0">{{ $review['next'] }}</p>
            </div>
        @endunless

        @if($review['primary_action'] === 'approve_domain' && $primaryCandidate)
            <div class="prospect-review-candidate p-4 p-lg-5 mb-4" data-review-primary-domain="{{ $primaryCandidate['domain'] }}">
                <div class="d-flex flex-column flex-md-row align-items-md-start justify-content-between gap-3">
                    <div class="min-w-0">
                        <div class="text-muted fs-8 mb-1">Site recommandé</div>
                        <div class="fs-2 fw-bold text-gray-900 prospect-review-domain">{{ $primaryCandidate['domain'] }}</div>
                        <p class="text-muted mb-3">{{ $primaryCandidate['explanation'] }}</p>
                        <div class="d-flex flex-wrap gap-2">
                            @foreach($primaryCandidate['sources'] as $source)
                                <span class="badge badge-light-primary">{{ $source }}</span>
                            @endforeach
                            <span class="badge badge-light-success">{{ $primaryCandidate['confidence'] }}</span>
                        </div>
                    </div>
                    <form method="POST" action="{{ route('admin.prospect_review.items.decide', $item) }}" data-review-decision-form data-review-primary-form>
                        @csrf
                        <input type="hidden" name="action" value="approve_domain">
                        <input type="hidden" name="selected_domain" value="{{ $primaryCandidate['domain'] }}">
                        @include('backend.contents.prospect_review.partials._return-context')
                        <button class="btn btn-primary text-nowrap" data-review-primary-action="approve_domain" data-review-pending-label="Enregistrement…">
                            Confirmer ce domaine
                        </button>
                    </form>
                </div>
            </div>
        @elseif($review['primary_action'] === 'retry' && $review['retry_blocked'])
            <div class="border border-warning rounded p-4 p-lg-5 bg-light-warning" data-review-retry-blocked>
                <div class="d-flex align-items-start gap-3">
                    <i class="bi bi-pause-circle fs-2 text-warning" aria-hidden="true"></i>
                    <div>
                        <div class="text-warning fw-bold fs-8 text-uppercase mb-2">Relance non disponible</div>
                        <h3 id="recommended-action-{{ $item->id }}" class="fs-3 mb-2">Réactivez d’abord le critère</h3>
                        <p class="mb-3">{{ $review['retry_blocked_message'] }}</p>
                        <p class="text-muted mb-4">Aucune relance ne sera placée dans la file tant que ce critère reste inactif.</p>
                        @if($review['retry_blocked_criteria_id'])
                            @can('view prospect_criteria')
                                <a class="btn btn-warning" href="{{ route('admin.prospect_criteria.view', $review['retry_blocked_criteria_id']) }}">
                                    Ouvrir le critère <i class="bi bi-arrow-up-right ms-2" aria-hidden="true"></i>
                                </a>
                            @endcan
                        @endif
                    </div>
                </div>
            </div>
        @elseif($review['primary_action'] === 'retry')
            <form
                method="POST"
                action="{{ route('admin.prospect_review.items.decide', $item) }}"
                @class(['border rounded p-4 p-lg-5', 'bg-light-primary' => ! $review['contact_collection_resume']])
                data-review-decision-form
                data-review-primary-form
            >
                @csrf
                <input type="hidden" name="action" value="retry">
                @include('backend.contents.prospect_review.partials._return-context')
                @if($review['contact_collection_resume'])
                    <div class="text-warning fw-bold fs-8 text-uppercase mb-2">Recherche interrompue</div>
                    <h3 id="recommended-action-{{ $item->id }}" class="fs-3 mb-2">Reprendre la collecte de contacts ?</h3>
                    <p class="text-muted mb-4">
                        La tentative pour <strong>{{ $review['selected_domain'] }}</strong> n’a pas abouti. Les données déjà conservées restent intactes.
                    </p>
                @endif
                @if(($review['enrichment_resume'] ?? false))
                    <div class="text-warning fw-bold fs-8 text-uppercase mb-2">Recherche interrompue</div>
                    <h3 id="recommended-action-{{ $item->id }}" class="fs-3 mb-2">Reprendre l’enrichissement de l’entreprise ?</h3>
                    <p class="text-muted mb-4">La tentative pour <strong>{{ $review['selected_domain'] }}</strong> n’a pas abouti. Les données déjà conservées restent intactes.</p>
                @endif
                @if($review['requires_reissue_confirmation'])
                    <div class="form-check mb-4">
                        <input class="form-check-input" type="checkbox" name="confirm_provider_reissue" value="1" id="primary-retry-{{ $item->id }}" required>
                        <label class="form-check-label" for="primary-retry-{{ $item->id }}">
                            Je confirme la relance de cette vérification fournisseur incertaine.
                        </label>
                    </div>
                @endif
                <button class="btn btn-primary" data-review-primary-action="retry" data-review-pending-label="Relance demandée…">
                    <i class="bi bi-arrow-repeat me-2" aria-hidden="true"></i>{{ $review['primary_label'] }}
                </button>
                @if($review['contact_collection_resume'] || ($review['enrichment_resume'] ?? false))
                    <a
                        class="btn btn-link text-muted text-decoration-underline d-block p-0 mt-3 text-start"
                        href="{{ isset($nextItem) && $nextItem ? route('admin.prospect_review.index', ['tab' => 'companies', 'batch' => $nextItem->prospect_batch_id, 'item' => $nextItem->id]) : route('admin.prospect_review.index', ['tab' => 'companies', 'batch' => $item->prospect_batch_id]) }}"
                        data-review-defer
                    >Décider plus tard</a>
                @endif
                @if($review['retry_cost_note'])
                    <details class="prospect-review-disclosure mt-3" data-review-provider-usage>
                        <summary>{{ $review['retry_cost_note'] }}</summary>
                        <div class="prospect-review-disclosure-body text-muted fs-8">
                            <p>La recherche retourne au maximum 10 adresses.</p>
                            <p>0 adresse retournée coûte 0 unité ; 1 à 10 adresses retournées coûtent 1 unité dans Fretiq.</p>
                            <p class="mb-2">Après déduplication, les doublons peuvent produire moins de nouveaux contacts dans Fretiq.</p>
                        </div>
                    </details>
                @endif
                @if($review['contact_collection_resume'] && $review['imported_contacts_count'] > 0)
                    <div class="text-muted fs-8 mt-3">
                        {{ $review['imported_contacts_count'] }} contact(s) déjà importé(s) et conservé(s). Aucun email n’a été envoyé par cet import.
                    </div>
                @endif
            </form>
        @elseif($review['primary_action'] === 'compare_collision')
            <div class="border rounded p-4 p-lg-5 bg-light-warning">
                @can('view companies')
                    <a class="btn btn-warning" href="{{ route('admin.companies.index') }}" data-review-primary-action="compare_collision">
                        Comparer dans Entreprises <i class="bi bi-arrow-up-right ms-2" aria-hidden="true"></i>
                    </a>
                @else
                    <p class="mb-0" data-review-primary-action="compare_collision">Demandez à un administrateur de comparer l’entreprise déjà associée à ce domaine.</p>
                @endcan
            </div>
        @endif
    </section>

    @if($domainChoices->isNotEmpty())
        <details class="prospect-review-disclosure mb-4" data-review-alternatives @if($review['domains_read_only']) data-review-collected-data @endif>
            <summary class="{{ $review['domains_read_only'] ? 'btn btn-light-primary' : '' }}">
                <span>{{ $review['domains_read_only'] ? 'Historique des domaines trouvés' : ($review['primary_action'] === 'approve_domain' ? 'Voir les autres domaines proposés' : 'Voir les domaines trouvés avant ce blocage') }}</span>
                <span class="badge badge-light-secondary">{{ $domainChoices->count() }}</span>
            </summary>
            <div class="prospect-review-disclosure-body">
                @if($review['domains_read_only'])
                    <p class="text-muted fs-8">Le domaine {{ $review['selected_domain'] }} est celui déjà utilisé pour l’enrichissement. Les autres pistes sont conservées à titre d’historique et ne peuvent pas modifier les données déjà collectées.</p>
                    <div class="d-grid gap-3">
                        @foreach($domainChoices as $candidate)
                            <div class="border rounded p-3 @if($candidate['domain'] === $review['selected_domain']) bg-light-success @else bg-light @endif">
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <span class="fw-bold prospect-review-domain">{{ $candidate['domain'] }}</span>
                                    @if($candidate['domain'] === $review['selected_domain'])<span class="badge badge-light-success">Domaine utilisé</span>@endif
                                </div>
                                <span class="d-block text-muted fs-8 mt-1">{{ $candidate['explanation'] }}</span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-muted fs-8">
                        Plusieurs outils peuvent retourner des homonymes, des annuaires ou des sites d’autres pays. La liste reste fermée pour garder la décision principale claire.
                    </p>

                    <form method="POST" action="{{ route('admin.prospect_review.items.decide', $item) }}" data-review-decision-form>
                    @csrf
                    <input type="hidden" name="action" value="approve_domain">
                    @include('backend.contents.prospect_review.partials._return-context')
                    <fieldset>
                        <legend class="visually-hidden">Choisir un autre domaine</legend>
                        <div class="d-grid gap-3">
                            @foreach($domainChoices as $index => $candidate)
                                <label class="d-flex align-items-start gap-3 border rounded p-3 @if(!$candidate['selectable']) bg-light @endif" for="alternative-domain-{{ $item->id }}-{{ $index }}">
                                    @if($candidate['selectable'])
                                        <input class="form-check-input mt-1" type="radio" name="selected_domain" id="alternative-domain-{{ $item->id }}-{{ $index }}" value="{{ $candidate['domain'] }}" @checked(($selectableChoices->first()['domain'] ?? null) === $candidate['domain'])>
                                    @else
                                        <span class="prospect-review-check-icon bg-light-secondary text-secondary flex-shrink-0"><i class="bi bi-slash-circle" aria-hidden="true"></i></span>
                                    @endif
                                    <span class="min-w-0 flex-grow-1">
                                        <span class="d-flex flex-wrap align-items-center gap-2">
                                            <span class="fw-bold prospect-review-domain">{{ $candidate['domain'] }}</span>
                                            <span class="badge badge-light-{{ $candidate['selectable'] ? 'warning' : 'secondary' }}">{{ $candidate['confidence'] }}</span>
                                        </span>
                                        <span class="d-block text-muted fs-8 mt-1">{{ $candidate['explanation'] }}</span>
                                        @if($candidate['sources'])
                                            <span class="d-flex flex-wrap gap-1 mt-2">
                                                @foreach($candidate['sources'] as $source)<span class="badge badge-light">{{ $source }}</span>@endforeach
                                            </span>
                                        @endif
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    @if($selectableChoices->isNotEmpty())
                        <button class="btn btn-sm btn-light-primary mt-4" data-review-pending-label="Enregistrement…">Utiliser le domaine sélectionné</button>
                    @endif
                    </form>
                @endif
            </div>
        </details>
    @endif

    <details class="prospect-review-disclosure" data-review-secondary-actions>
        <summary class="btn btn-light">Autres actions</summary>
        <div class="prospect-review-disclosure-body">
            <p class="text-muted fs-8">Utilisez ces actions seulement si la recommandation ci-dessus ne convient pas.</p>
            <div class="prospect-review-decision-grid">
                @if($review['primary_action'] !== 'retry')
                    <form method="POST" action="{{ route('admin.prospect_review.items.decide', $item) }}" class="border rounded p-4" data-review-decision-form>
                        @csrf
                        <input type="hidden" name="action" value="retry">
                        @include('backend.contents.prospect_review.partials._return-context')
                        @if($review['requires_reissue_confirmation'])
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="confirm_provider_reissue" value="1" id="secondary-retry-{{ $item->id }}" required>
                                <label class="form-check-label fs-8" for="secondary-retry-{{ $item->id }}">Je confirme la relance fournisseur.</label>
                            </div>
                        @endif
                        <div class="fw-semibold mb-2">Relancer la recherche</div>
                        <p class="text-muted fs-8">Seule cette entreprise retournera dans la file de traitement.</p>
                        <button class="btn btn-sm btn-light-primary" data-review-pending-label="Relance demandée…">Réessayer le traitement</button>
                    </form>
                @endif

                <form method="POST" action="{{ route('admin.prospect_review.items.decide', $item) }}" class="border rounded p-4" data-review-decision-form data-review-confirm="Exclure cette entreprise de ce lot ?">
                    @csrf
                    <input type="hidden" name="action" value="reject">
                    @include('backend.contents.prospect_review.partials._return-context')
                    <div class="fw-semibold mb-2">Exclure cette ligne</div>
                    <p class="text-muted fs-8">Aucune entreprise ne sera créée à partir de cette ligne.</p>
                    <label class="form-label fs-8" for="reject-reason-{{ $item->id }}">Motif</label>
                    <select class="form-select form-select-sm mb-3" id="reject-reason-{{ $item->id }}" name="reason">
                        <option value="not_a_match">Ce n’est pas la bonne entreprise</option>
                        <option value="not_relevant">Entreprise non pertinente</option>
                        <option value="bad_data">Donnée incorrecte</option>
                    </select>
                    <button class="btn btn-sm btn-light-danger" data-review-pending-label="Exclusion en cours…" data-review-confirm="Exclure cette entreprise de ce lot ?">Exclure cette entreprise</button>
                </form>
            </div>
        </div>
    </details>
</article>
