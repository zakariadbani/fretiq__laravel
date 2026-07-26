{{--
    Segment hero action buttons shared by view and edit.

    "Lancer une campagne" is a plain cross-route link, so on the edit form the crud-tabs.js
    dirty-navigation guard prompts before discarding unsaved edits — no extra wiring needed.

    Variables: $model, $isView (bool).
--}}

@if($isView)
    @can('edit segments')
        <a href="{{ route('admin.segments.edit', $model->id) }}" class="btn btn-sm btn-primary">
            <i class="bi bi-pencil me-1"></i>
            Modifier
        </a>
    @endcan
@endif

@can('create campaigns')
    @if(Route::has('admin.campaigns.create'))
        <a href="{{ route('admin.campaigns.create', ['segment_id' => $model->id]) }}" class="btn btn-sm btn-light btn-active-light-primary">
            <i class="bi bi-rocket me-1"></i>
            Lancer une campagne
        </a>
    @endif
@endcan
