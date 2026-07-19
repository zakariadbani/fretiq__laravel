{{--
    Segment hero action buttons — ACTIONS ONLY, rendered identically on the view page
    and the edit form (parity is the point; do not re-introduce a mode branch).

    "Modifier" was removed: the tab bar already cross-links view ↔ edit.
    "Retour à la liste" was removed: the breadcrumb on both pages already links to the index.

    "Lancer une campagne" is a plain cross-route link, so on the edit form the crud-tabs.js
    dirty-navigation guard prompts before discarding unsaved edits — no extra wiring needed.

    Variables: $model. ($isView is still passed by the _header-with-tabs shim but is no
    longer consumed.)
--}}

@can('create campaigns')
    @if(Route::has('admin.campaigns.create'))
        <a href="{{ route('admin.campaigns.create', ['segment_id' => $model->id]) }}" class="btn btn-sm btn-light btn-active-light-primary">
            <i class="bi bi-rocket me-1"></i>
            Lancer une campagne
        </a>
    @endif
@endcan
