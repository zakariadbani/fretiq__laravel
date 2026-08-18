<x-default-layout>

@section('title', $model->exists ? 'Préparer le lot' : 'Importer des entreprises')

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[
        ['label' => 'Lots', 'route' => 'admin.prospect_batches.index'],
        ['label' => $model->exists ? $model->name : 'Nouveau lot'],
    ]" />
@endsection

@section('toolbar_actions')
    @include('backend.elements.form-actions', ['variant' => 'toolbar', 'backRoute' => 'admin.prospect_batches.index'])
@endsection

{{--
    ProspectBatch create/edit form — the wizard here is steps 2-4 of the CREATE
    flow (see ProspectBatchController::store()); edit() only ever renders a
    draft batch (a launched batch redirects to the view page instead).

    Edit mode wraps the wizard in the shared hero + tabbar shell (mirrors
    prospect_criteria's form.blade.php) so the page matches the rest of the
    CRUD contract instead of floating a bare stepper. Create mode keeps the
    simple header — there is no persisted model yet to drive the hero.
--}}

<form id="prospect_batch_form" method="POST" action="{{ $route }}" enctype="multipart/form-data">
    @csrf
    @if(strtoupper($method ?? 'POST') !== 'POST') @method($method) @endif

    @if($model->exists)
        @include('backend.contents.prospect_batches.partials._header-with-tabs', [
            'model'       => $model,
            'currentPage' => 'edit',
        ])
    @else
        <div class="card mb-5">
            <div class="card-body py-6">
                <h2 class="fs-3 fw-bold m-0">
                    <i class="bi bi-building-add text-primary fs-3 me-2"></i>
                    Importer des entreprises
                </h2>
            </div>
        </div>
    @endif

    <div class="tab-content" id="prospect_batch_tab_content">
        <div class="tab-pane fade show active" id="prospect_batch_general" role="tabpanel">

            <div id="prospect_batch_wizard" class="stepper stepper-pills"
                 data-initial-step="{{ $initialStep ?? 1 }}"
                 data-batch-id="{{ $model->id }}"
                 data-estimate-url="{{ $model->exists ? route('admin.prospect_batches.estimate', $model) : '' }}"
                 data-confirm-url="{{ $model->exists ? route('admin.prospect_batches.confirm', $model) : '' }}"
                 data-status-url="{{ $model->exists ? route('admin.prospect_batches.status', $model) : '' }}">

                <div class="card mb-7">
                    <div class="card-body py-6">
                        <div class="stepper-nav flex-center flex-wrap gap-4" aria-label="Étapes de l’import">
                            @foreach([
                                1 => ['Ajouter les entreprises', 'bi-building-add'],
                                2 => ['Choisir la qualité', 'bi-sliders'],
                                3 => ['Vérifier et lancer', 'bi-check2-circle'],
                                4 => ['Traitement', 'bi-hourglass-split'],
                            ] as $number => [$label, $icon])
                                <div class="stepper-item mx-2 my-2" data-kt-stepper-element="nav" data-step-nav="{{ $number }}">
                                    <div class="stepper-wrapper d-flex align-items-center">
                                        <div class="stepper-icon w-40px h-40px"><i class="bi {{ $icon }}"></i><span class="stepper-number">{{ $number }}</span></div>
                                        <div class="stepper-label"><div class="stepper-title fs-6">{{ $label }}</div></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body p-6 p-lg-10">
                        <div id="prospect-wizard-alert" class="alert d-none" role="alert" aria-live="assertive"></div>
                        @include('backend.contents.prospect_batches.partials._step-companies')
                        @include('backend.contents.prospect_batches.partials._step-quality')
                        @include('backend.contents.prospect_batches.partials._step-cost')
                        @include('backend.contents.prospect_batches.partials._step-processing')
                    </div>
                </div>

                <div class="sticky-bottom bg-body border-top mt-7 py-4" data-wizard-actions>
                    <div class="container-fluid px-0 d-flex justify-content-between gap-3">
                        <button type="button" class="btn btn-light" data-wizard-back>Retour</button>
                        <button type="button" class="btn btn-primary flex-grow-1 flex-sm-grow-0" data-wizard-next>
                            <span data-next-label>Continuer</span>
                            <span class="spinner-border spinner-border-sm ms-2 d-none" data-next-spinner></span>
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </div>
</form>

@push('scripts')
    @php
        $wizardAssetPath = 'assets/js/custom/backend/prospect-batch-wizard.js';
        $wizardAssetFile = public_path($wizardAssetPath);
        $wizardAssetUrl = asset($wizardAssetPath);

        if (is_file($wizardAssetFile)) {
            $wizardAssetUrl .= '?v='.filemtime($wizardAssetFile);
        }
    @endphp
    <script src="{{ $wizardAssetUrl }}"></script>
@endpush

</x-default-layout>
