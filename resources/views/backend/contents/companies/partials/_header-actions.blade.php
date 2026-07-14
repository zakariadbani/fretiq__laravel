{{--
    Company hero action buttons — only rendered on the view page.
    Used by _header-with-tabs.blade.php shim.

    Variables: $model, $isView (bool)
--}}

@if($isView)
    @php($isRejected = $model->qualification_status === 'rejected')

    @can('view companies')
        <a href="{{ $isRejected ? route('admin.companies.archive') : route('admin.companies.index') }}" class="btn btn-sm btn-light">
            <i class="bi bi-arrow-left me-1"></i>
            {{ $isRejected ? 'Retour aux archives' : 'Retour à la liste' }}
        </a>
    @endcan

    @can('edit companies')
        @if($isRejected)
            <form action="{{ route('admin.companies.restore', $model->id) }}" method="POST" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-sm btn-success">
                    <i class="bi bi-arrow-counterclockwise me-1"></i>
                    Restaurer
                </button>
            </form>
        @else
            <a href="{{ route('admin.companies.edit', $model->id) }}" class="btn btn-sm btn-primary">
                <i class="bi bi-pencil me-1"></i>
                Modifier
            </a>
        @endif
    @endcan

    @can('create campaigns')
        @if(!$isRejected && Route::has('admin.campaigns.create'))
            <a href="{{ route('admin.campaigns.create', ['company_id' => $model->id]) }}" class="btn btn-sm btn-light btn-active-light-primary">
                <i class="bi bi-rocket me-1"></i>
                Lancer une campagne
            </a>
        @endif
    @endcan
@endif
