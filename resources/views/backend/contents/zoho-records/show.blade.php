<x-default-layout>
    @section('title', 'Fiche CRM Zoho')

    @section('breadcrumbs')
        <x-crud.breadcrumb :items="[['label' => 'CRM Zoho'], ['label' => ucfirst($module)], ['label' => 'Fiche']]" />
    @endsection

    <div class="d-flex flex-wrap flex-stack mb-6">
        <div>
            <h1 class="d-flex text-gray-900 fw-bold my-1 fs-3">{{ $record->getAttribute($fields[1] ?? 'zoho_id') ?: $record->zoho_id }}</h1>
            <span class="text-muted">CRM Zoho · lecture seule</span>
        </div>
        <a href="{{ url('/admin/zoho/records/'.$module).(count($filters) ? '?'.http_build_query($filters) : '') }}" class="btn btn-light">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Retour à la liste
        </a>
    </div>

    <div class="card mb-6">
        <div class="card-body p-0">
            <ul class="nav nav-tabs nav-line-tabs mb-5 fs-6" role="tablist">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#overview" type="button" role="tab">Informations</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#activities" type="button" role="tab">Activités <span class="badge badge-light-primary ms-1">{{ $activities->count() }}</span></button></li>
                @if($module === 'quotes')<li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#items" type="button" role="tab">Lignes de cotation <span class="badge badge-light-primary ms-1">{{ $items->count() }}</span></button></li>@endif
                @if($canViewRawPayload)<li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#raw" type="button" role="tab">Payload brut</button></li>@endif
            </ul>
            <div class="tab-content px-8 pb-8">
                <div class="tab-pane fade show active" id="overview" role="tabpanel">
                    <dl class="row mb-0">
                        @foreach($fields as $field)
                            <dt class="col-md-4 text-muted">{{ str_replace('_', ' ', ucfirst($field)) }}</dt>
                            <dd class="col-md-8">@if(is_array($record->getAttribute($field))){{ implode(', ', $record->getAttribute($field)) }}@elseif($record->getAttribute($field) instanceof \DateTimeInterface){{ $record->getAttribute($field)->format('d/m/Y H:i') }}@else{{ $record->getAttribute($field) ?? '—' }}@endif</dd>
                        @endforeach
                    </dl>
                </div>
                <div class="tab-pane fade" id="activities" role="tabpanel">
                    @forelse($activities as $activity)
                        <div class="border-bottom py-3"><strong>{{ $activity->subject ?: 'Activité' }}</strong><br><span class="text-muted">{{ $activity->activity_type }} · {{ $activity->activity_at?->format('d/m/Y H:i') ?? 'Date non renseignée' }}</span></div>
                    @empty <p class="text-muted mb-0">Aucune activité visible.</p> @endforelse
                </div>
                @if($module === 'quotes')
                    <div class="tab-pane fade" id="items" role="tabpanel">
                        <div class="table-responsive"><table class="table table-row-dashed"><thead><tr><th>Produit</th><th>Quantité</th><th>Prix unitaire</th><th>Total</th><th>Devise</th></tr></thead><tbody>
                        @forelse($items as $item)<tr><td>{{ $item->product_name }}</td><td>{{ $item->quantity }}</td><td>{{ $item->unit_price ?? '—' }}</td><td>{{ $item->total ?? '—' }}</td><td>{{ $item->currency_code }}</td></tr>@empty<tr><td colspan="5" class="text-muted">Aucune ligne visible.</td></tr>@endforelse
                        </tbody></table></div>
                    </div>
                @endif
                @if($canViewRawPayload)<div class="tab-pane fade" id="raw" role="tabpanel"><pre class="bg-light p-4 rounded text-break">{{ json_encode($record->raw_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></div>@endif
            </div>
        </div>
    </div>
</x-default-layout>
