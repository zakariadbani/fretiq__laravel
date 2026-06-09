<x-default-layout>

@section('title')
    Séquence — {{ e($model->name) }}
@endsection

@section('breadcrumbs')
    <ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
        <li class="breadcrumb-item text-muted">
            <a href="{{ route('admin.dashboard') }}" class="text-muted text-hover-primary">Accueil</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">
            <a href="{{ route('admin.sequences.index') }}" class="text-muted text-hover-primary">Séquences</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">{{ e($model->name) }}</li>
    </ul>
@endsection

{{-- Action buttons --}}
<div class="d-flex align-items-center gap-2 mb-6 flex-wrap">
    @can('view sequences')
        <a href="{{ route('admin.sequences.index') }}" class="btn btn-sm fw-bold btn-light">
            <i class="bi bi-arrow-left me-1"></i>
            Retour à la liste
        </a>
    @endcan

    @can('edit sequences')
        <a href="{{ route('admin.sequences.edit', $model->id) }}" class="btn btn-sm fw-bold btn-primary">
            <i class="bi bi-pencil me-1"></i>
            Modifier
        </a>
    @endcan
</div>

{{-- ── Sequence summary card ───────────────────────────────────────────── --}}
<div class="row g-5 mb-5">
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title fw-bolder m-0">
                    <i class="bi bi-layers text-primary fs-3 me-2"></i>
                    Informations
                </h3>
            </div>
            <div class="card-body border-top">

                <div class="row mb-5">
                    <label class="col-lg-5 fw-bold text-muted">Nom</label>
                    <div class="col-lg-7">
                        <span class="fw-bolder fs-6 text-gray-900">{{ e($model->name) }}</span>
                    </div>
                </div>

                <div class="row mb-5">
                    <label class="col-lg-5 fw-bold text-muted">Statut</label>
                    <div class="col-lg-7">
                        @if($model->is_active)
                            <span class="badge badge-light-success">Actif</span>
                        @else
                            <span class="badge badge-light-secondary">Inactif</span>
                        @endif
                    </div>
                </div>

                <div class="row mb-5">
                    <label class="col-lg-5 fw-bold text-muted">Stop sur réponse</label>
                    <div class="col-lg-7">
                        @if($model->stop_on_reply)
                            <span class="badge badge-light-info">Oui</span>
                        @else
                            <span class="badge badge-light-secondary">Non</span>
                        @endif
                    </div>
                </div>

                <div class="row mb-5">
                    <label class="col-lg-5 fw-bold text-muted">Étapes</label>
                    <div class="col-lg-7">
                        <span class="badge badge-light-primary">{{ $model->steps->count() }}</span>
                    </div>
                </div>

                <div class="row mb-0">
                    <label class="col-lg-5 fw-bold text-muted">Créé le</label>
                    <div class="col-lg-7">
                        <span class="fw-semibold">{{ $model->created_at?->format('d/m/Y H:i') ?? '—' }}</span>
                    </div>
                </div>

            </div>
        </div>
    </div>

    {{-- Step count summary --}}
    <div class="col-lg-8">
        <div class="card h-100 d-flex align-items-center justify-content-center">
            <div class="card-body text-center py-8">
                <i class="bi bi-diagram-3 fs-2x text-primary mb-3 d-block"></i>
                <div class="fw-bolder fs-1 text-gray-900">{{ $model->steps->count() }}</div>
                <div class="text-muted fw-semibold mt-1">étape(s) dans cette séquence</div>
                <div class="text-muted fs-7 mt-1">{{ $model->enrollments->count() }} inscription(s) au total</div>
            </div>
        </div>
    </div>
</div>

{{-- ── Step Builder ─────────────────────────────────────────────────────── --}}
<div class="card mb-5">
    <div class="card-header border-0 pt-5">
        <h3 class="card-title fw-bolder m-0">
            <i class="bi bi-list-ol text-info fs-3 me-2"></i>
            Étapes de la séquence
        </h3>
    </div>
    <div class="card-body border-top p-0">

        @if($model->steps->isNotEmpty())
        <div class="table-responsive">
            <table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-3 mb-0">
                <thead>
                    <tr class="fw-bold text-muted bg-light">
                        <th class="ps-7">N°</th>
                        <th>Délai (jours)</th>
                        <th>Modèle</th>
                        <th>Sujet</th>
                        @can('edit sequences')
                        <th class="text-end pe-7">Actions</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @foreach($model->steps as $step)
                    <tr>
                        <td class="ps-7">
                            <span class="badge badge-circle badge-light-primary">{{ $step->step_no }}</span>
                        </td>
                        <td>
                            <span class="fw-semibold">
                                {{ $step->delay_days === 0 ? 'Immédiat' : $step->delay_days . ' jour(s)' }}
                            </span>
                        </td>
                        <td>
                            <span class="fw-semibold">{{ $step->template?->name ?? '—' }}</span>
                        </td>
                        <td>
                            <span class="text-muted">{{ $step->subject ? e($step->subject) : '(sujet du modèle)' }}</span>
                        </td>
                        @can('edit sequences')
                        <td class="text-end pe-7">
                            <form method="POST"
                                  action="{{ route('admin.sequences.deleteStep', [$model->id, $step->id]) }}"
                                  onsubmit="return confirm('Supprimer cette étape ?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-icon btn-light-danger">
                                    <i class="bi bi-trash fs-5"></i>
                                </button>
                            </form>
                        </td>
                        @endcan
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
            <div class="text-center py-8 text-muted">
                <i class="bi bi-list-ol fs-2x mb-3 d-block"></i>
                Aucune étape définie. Ajoutez la première étape ci-dessous.
            </div>
        @endif

    </div>

    {{-- Add step inline form --}}
    @can('edit sequences')
    <div class="card-footer border-top pt-5 pb-6 px-9">
        <h5 class="fw-bold mb-5 text-gray-700">
            <i class="bi bi-plus-circle text-primary me-2"></i>
            Ajouter une étape
        </h5>
        <form method="POST" action="{{ route('admin.sequences.addStep', $model->id) }}">
            @csrf
            <div class="row g-4 align-items-end">

                <div class="col-md-2">
                    <label class="required fw-semibold fs-7 mb-2">Délai (jours)</label>
                    <input type="number"
                           name="delay_days"
                           class="form-control form-control-solid form-control-sm"
                           placeholder="0"
                           min="0"
                           value="{{ old('delay_days', 1) }}"
                           required />
                    <div class="form-text text-muted fs-8">0 = immédiat</div>
                </div>

                <div class="col-md-4">
                    <label class="required fw-semibold fs-7 mb-2">Modèle d'email</label>
                    <select name="template_id" class="form-select form-select-solid form-select-sm" required>
                        <option value="">Sélectionner un modèle...</option>
                        @foreach($templates as $tpl)
                            <option value="{{ $tpl->id }}" {{ old('template_id') == $tpl->id ? 'selected' : '' }}>
                                {{ e($tpl->name) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="fw-semibold fs-7 mb-2">Sujet <span class="text-muted">(optionnel)</span></label>
                    <input type="text"
                           name="subject"
                           class="form-control form-control-solid form-control-sm"
                           placeholder="Laissez vide pour utiliser le sujet du modèle"
                           value="{{ old('subject') }}" />
                </div>

                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-plus me-1"></i>
                        Ajouter
                    </button>
                </div>

            </div>
        </form>
    </div>
    @endcan
</div>

{{-- ── Enrollments (Inscriptions) ───────────────────────────────────────── --}}
@php
    $enrollments = $model->enrollments()->with('contact')->orderByDesc('created_at')->get();
    $enrollmentStatuses = config('global.data.sequence_enrollment_statuses', []);
@endphp

<div class="card">
    <div class="card-header border-0 pt-5">
        <h3 class="card-title fw-bolder m-0">
            <i class="bi bi-person-check text-success fs-3 me-2"></i>
            Inscriptions ({{ $enrollments->count() }})
        </h3>
    </div>
    <div class="card-body border-top p-0">

        @if($enrollments->isNotEmpty())
        <div class="table-responsive">
            <table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-3 mb-0">
                <thead>
                    <tr class="fw-bold text-muted bg-light">
                        <th class="ps-7">Contact</th>
                        <th>Étape actuelle</th>
                        <th>Statut</th>
                        <th>Prochain envoi</th>
                        @can('edit sequences')
                        <th class="text-end pe-7">Actions</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @foreach($enrollments as $enrollment)
                    @php
                        $statusCfg = $enrollmentStatuses[$enrollment->status] ?? [];
                    @endphp
                    <tr>
                        <td class="ps-7 fw-semibold">
                            {{ $enrollment->contact?->email ? e($enrollment->contact->email) : '—' }}
                        </td>
                        <td>
                            <span class="badge badge-light-primary">
                                Étape {{ $enrollment->current_step ?? 0 }}
                            </span>
                        </td>
                        <td>
                            @if($statusCfg)
                                <span class="badge badge-light-{{ $statusCfg['color'] }}">{{ $statusCfg['label'] }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            {{ $enrollment->next_send_at?->format('d/m/Y H:i') ?? '—' }}
                        </td>
                        @can('edit sequences')
                        <td class="text-end pe-7">
                            <div class="d-flex gap-1 justify-content-end">
                                @if($enrollment->status === 'active')
                                    <form method="POST"
                                          action="{{ route('admin.sequences.pauseEnrollment', [$model->id, $enrollment->id]) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-light-warning" title="Mettre en pause">
                                            <i class="bi bi-pause fs-6"></i> Pause
                                        </button>
                                    </form>
                                @endif
                                @if($enrollment->status === 'paused')
                                    <form method="POST"
                                          action="{{ route('admin.sequences.resumeEnrollment', [$model->id, $enrollment->id]) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-light-success" title="Reprendre">
                                            <i class="bi bi-play fs-6"></i> Reprendre
                                        </button>
                                    </form>
                                @endif
                                @if(in_array($enrollment->status, ['active', 'paused']))
                                    <form method="POST"
                                          action="{{ route('admin.sequences.stopEnrollment', [$model->id, $enrollment->id]) }}"
                                          onsubmit="return confirm('Stopper définitivement cette inscription ?');">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-light-danger" title="Stopper">
                                            <i class="bi bi-stop-circle fs-6"></i> Stopper
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                        @endcan
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
            <div class="text-center py-8 text-muted">
                <i class="bi bi-person-x fs-2x mb-3 d-block"></i>
                Aucune inscription pour cette séquence.
            </div>
        @endif

    </div>
</div>

</x-default-layout>
