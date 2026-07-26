{{--
    CampaignTemplate hero action buttons shared by view and edit.
    Used by _header-with-tabs.blade.php shim.

    Variables: $model, $isView (bool)
--}}

@if($isView)
    @can('edit campaign_templates')
        <a href="{{ route('admin.campaign_templates.edit', $model->id) }}" class="btn btn-sm btn-primary">
            <i class="bi bi-pencil me-1"></i>
            Modifier
        </a>
    @endcan
@endif

@can('create campaigns')
    @if(Route::has('admin.campaigns.create'))
        <a href="{{ route('admin.campaigns.create', ['template_id' => $model->id]) }}" class="btn btn-sm btn-light btn-active-light-primary">
            <i class="bi bi-rocket me-1"></i>
            Creer une campagne
        </a>
    @endif
@endcan
