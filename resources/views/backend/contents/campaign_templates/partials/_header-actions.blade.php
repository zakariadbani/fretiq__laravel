{{--
    CampaignTemplate hero action buttons — only rendered on the view page.
    Used by _header-with-tabs.blade.php shim.

    Variables: $model, $isView (bool)
--}}

@if($isView)
    @can('view campaign_templates')
        <a href="{{ route('admin.campaign_templates.index') }}" class="btn btn-sm btn-light">
            <i class="bi bi-arrow-left me-1"></i>
            Retour à la liste
        </a>
    @endcan

    @can('edit campaign_templates')
        <a href="{{ route('admin.campaign_templates.edit', $model->id) }}" class="btn btn-sm btn-primary">
            <i class="bi bi-pencil me-1"></i>
            Modifier
        </a>
    @endcan

    @can('create campaigns')
        @if(Route::has('admin.campaigns.create'))
            <a href="{{ route('admin.campaigns.create', ['template_id' => $model->id]) }}" class="btn btn-sm btn-light btn-active-light-primary">
                <i class="bi bi-rocket me-1"></i>
                Creer une campagne
            </a>
        @endif
    @endcan
@endif
