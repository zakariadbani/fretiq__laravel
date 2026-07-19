{{--
    _enrichment-recap.blade.php — enrichment audit card for the company VIEW page.

    Answers "why does this company have no contacts?" without leaving the view page:
      1. the enrichment_status badge (NULL renders as « Non tenté », never a dash —
         distinguishing "never attempted" from "attempted, found nothing" is the
         whole point of the column);
      2. every e-mail Hunter returned, each with its per-email confidence score.

    Props:
        $model  (Company) — must have enrichment_status + enrichment_data

    enrichment_data is rendered with {{ }} only — never {!! !!}.
--}}
@php
    $statusKey = $model->enrichment_status;
    $statusCfg = $statusKey
        ? (config('global.data.company_enrichment_statuses.' . $statusKey)
            ?? ['label' => $statusKey, 'color' => 'secondary'])
        : config('global.data.company_enrichment_status_null', ['label' => 'Non tenté', 'color' => 'secondary']);

    $enrichmentEmails = data_get($model->enrichment_data, 'emails');
    $enrichmentEmails = is_array($enrichmentEmails) ? $enrichmentEmails : [];
@endphp

<div class="card mb-5 mb-xl-10">
    <div class="card-header border-0 pt-5">
        <h3 class="card-title align-items-start flex-column">
            <span class="card-label fw-bold fs-3 mb-1">Enrichissement</span>
            <span class="text-muted fw-semibold fs-7">Résultat de la récupération des contacts</span>
        </h3>
    </div>
    <div class="card-body border-top p-9">

        {{-- Status badge --}}
        <div class="d-flex align-items-center mb-5">
            <span class="text-muted fw-semibold fs-6 me-3">Statut :</span>
            <span class="badge badge-light-{{ $statusCfg['color'] ?? 'secondary' }} fs-7">{{ $statusCfg['label'] ?? 'Non tenté' }}</span>
        </div>

        {{-- Hunter e-mails with per-email confidence --}}
        @if($enrichmentEmails === [])
            <span class="text-muted fs-6">Aucun email trouvé</span>
        @else
            <div class="d-flex flex-column gap-3">
                @foreach($enrichmentEmails as $email)
                    @php
                        $address    = is_array($email) ? ($email['value'] ?? null) : null;
                        $confidence = is_array($email) ? ($email['confidence'] ?? null) : null;
                        $fullName   = is_array($email)
                            ? trim(($email['first_name'] ?? '') . ' ' . ($email['last_name'] ?? ''))
                            : '';
                    @endphp
                    <div class="border rounded bg-light p-3">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            @if($address)
                                <a href="mailto:{{ $address }}" class="fw-bold text-gray-900 text-hover-primary">{{ $address }}</a>
                            @else
                                <span class="fw-bold text-muted">Email inconnu</span>
                            @endif

                            @if($confidence !== null)
                                <span class="badge badge-light-success">Confiance {{ $confidence }}%</span>
                            @else
                                <span class="badge badge-light-secondary">Confiance inconnue</span>
                            @endif
                        </div>

                        @if($fullName !== '')
                            <div class="text-muted fs-7 mt-2">Contact : {{ $fullName }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

    </div>
</div>
