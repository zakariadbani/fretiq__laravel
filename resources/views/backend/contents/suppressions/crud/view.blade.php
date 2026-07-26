<x-default-layout>

@section('title')
    Suppression — {{ $model->email }}
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Suppressions', 'route' => 'admin.suppressions.index'], ['label' => $model->email]]" />
@endsection

@section('toolbar_actions')
    @include('backend.elements.form-actions', ['variant' => 'toolbar', 'backRoute' => 'admin.suppressions.index'])
@endsection

@include('backend.contents.suppressions.partials._header-with-tabs', [
    'model'       => $model,
    'currentPage' => 'view',
])

<div class="tab-content">
    <div class="tab-pane fade show active" id="suppression_apercu" role="tabpanel">
        <div class="row g-5">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title fw-bolder m-0">
                    <i class="bi bi-slash-circle text-danger fs-3 me-2"></i>
                    Détails de la suppression
                </h3>
            </div>
            <div class="card-body border-top">

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Email</label>
                    <div class="col-lg-8">
                        <span class="fw-bolder fs-6 text-gray-900">{{ $model->email }}</span>
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Motif</label>
                    <div class="col-lg-8">
                        @php
                            $reason = config('global.data.suppression_reasons.' . $model->reason);
                        @endphp
                        @if($reason)
                            <span class="badge badge-light-{{ $reason['color'] }}">{{ $reason['label'] }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Source</label>
                    <div class="col-lg-8">
                        @php
                            $source = config('global.data.suppression_sources.' . $model->source);
                        @endphp
                        @if($source)
                            <span class="badge badge-light-{{ $source['color'] }}">{{ $source['label'] }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Contact lié</label>
                    <div class="col-lg-8">
                        @if($model->contact)
                            <a href="{{ route('admin.contacts.view', $model->contact->id) }}" class="fw-semibold text-primary">
                                {{ $model->contact->email }}
                            </a>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </div>
                </div>

                <div class="row mb-0">
                    <label class="col-lg-4 fw-bold text-muted">Supprimé le</label>
                    <div class="col-lg-8">
                        <span class="fw-semibold">{{ $model->created_at?->format('d/m/Y H:i') ?? '—' }}</span>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

    </div>
</div>

</x-default-layout>
