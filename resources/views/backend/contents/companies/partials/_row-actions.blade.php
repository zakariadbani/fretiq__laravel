{{--
    Companies DataTable row actions.
    Replicates the generic datatable/actions partial PLUS adds the "Récupérer les contacts"
    enrich button for users with 'enrich companies' permission.

    Variables injected by CompaniesDataTable::dataTable() action column:
        $model      — Company Eloquent instance
--}}
<div class="d-flex justify-content-end flex-shrink-0">
    @php($isRejected = $model->qualification_status === 'rejected')

    @can('edit companies')
        @if($isRejected)
            <form action="{{ route('admin.companies.restore', $model->id) }}" method="POST" class="d-inline">
                @csrf
                <button type="submit"
                        class="btn btn-icon btn-bg-light btn-active-color-success btn-sm me-1"
                        data-bs-toggle="tooltip"
                        aria-label="Restaurer l'entreprise"
                        title="Restaurer dans les entreprises actives">
                    <i class="bi bi-arrow-counterclockwise fs-4"></i>
                </button>
            </form>
        @else
            {{-- Edit Button --}}
            <a href="{{ route('admin.companies.edit', $model->id) }}"
               class="btn btn-icon btn-bg-light btn-active-color-primary btn-sm me-1"
               data-bs-toggle="tooltip"
               aria-label="Modifier l'entreprise"
               title="Modifier">
                <i class="bi bi-pencil fs-4"></i>
            </a>
        @endif
    @endcan

    @can('view companies')
    {{-- View Button --}}
    <a href="{{ route('admin.companies.view', $model->id) }}"
       class="btn btn-icon btn-bg-light btn-active-color-primary btn-sm me-1"
       data-bs-toggle="tooltip"
       aria-label="Voir l'entreprise"
       title="Voir">
        <i class="bi bi-eye fs-4"></i>
    </a>
    @endcan

    @can('enrich companies')
    @if($model->domain && !$isRejected)
    {{-- Enrich Button — only for companies that have a domain --}}
    <a href="javascript:void(0);"
       class="btn btn-icon btn-bg-light btn-active-color-success btn-sm me-1 enrich-btn"
       data-id="{{ $model->id }}"
       data-url="{{ route('admin.companies.enrich', $model->id) }}"
       data-bs-toggle="tooltip"
       aria-label="Récupérer les contacts"
       title="Récupérer les contacts">
        <i class="bi bi-person-plus fs-4"></i>
    </a>
    @endif
    @endcan

    @can('delete companies')
    @if(!$isRejected)
    {{-- Delete Button --}}
    <a href="javascript:void(0);"
       class="btn btn-icon btn-bg-light btn-active-color-danger btn-sm delete-btn"
       data-id="{{ $model->id }}"
       data-url="{{ route('admin.companies.delete', $model->id) }}"
       data-bs-toggle="tooltip"
       aria-label="Supprimer l'entreprise"
       title="Supprimer">
        <i class="bi bi-trash fs-4"></i>
    </a>
    @endif
    @endcan
</div>
