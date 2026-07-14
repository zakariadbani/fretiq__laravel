{{--
    Segment hero action buttons — only rendered on the view page.
    Used by _header-with-tabs.blade.php shim.

    Variables: $model, $isView (bool)
--}}

@if($isView)
    @can('view segments')
        <a href="{{ route('admin.segments.index') }}" class="btn btn-sm btn-light">
            <i class="bi bi-arrow-left me-1"></i>
            Retour à la liste
        </a>
    @endcan

    @can('edit segments')
        <a href="{{ route('admin.segments.edit', $model->id) }}" class="btn btn-sm btn-primary">
            <i class="bi bi-pencil me-1"></i>
            Modifier
        </a>
    @endcan

    @can('create campaigns')
        @if(Route::has('admin.campaigns.create'))
            <a href="{{ route('admin.campaigns.create', ['segment_id' => $model->id]) }}" class="btn btn-sm btn-light btn-active-light-primary">
                <i class="bi bi-rocket me-1"></i>
                Lancer une campagne
            </a>
        @endif
    @endcan
@endif
