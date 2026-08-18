{{--
    Thin shim: ProspectBatch hero + tab nav.
    Resolves config from $viewConfig (injected by controller) or builds it on-the-fly.
    Delegates rendering to the generic backend.partials.crud._tabbar partial.

    Usage: @include('backend.contents.prospect_batches.partials._header-with-tabs', [
        'model'       => $model,
        'currentPage' => 'view|edit',
    ])
--}}

@php
    $config = $viewConfig ?? \App\Crud\ViewConfigs\ProspectBatchViewConfig::make($model);
@endphp

@include('backend.partials.crud._tabbar', [
    'model'       => $model,
    'currentPage' => $currentPage ?? 'view',
    'config'      => $config,
    'actions'     => $actions ?? '',
])
