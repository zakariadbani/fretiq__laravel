{{--
    Thin shim: SenderIdentity hero + tab nav.
    Resolves config from $viewConfig (injected by controller) or builds it on-the-fly.
    Delegates rendering to the generic backend.partials.crud._tabbar partial.

    Usage: @include('backend.contents.sender_identities.partials._header-with-tabs', [
        'model'       => $model,
        'currentPage' => 'view|edit',
    ])
--}}

@php
    $isView = ($currentPage ?? 'view') === 'view';
    $config = $viewConfig ?? \App\Crud\ViewConfigs\SenderIdentityViewConfig::make($model);
@endphp

@include('backend.partials.crud._tabbar', [
    'model'       => $model,
    'currentPage' => $currentPage ?? 'view',
    'config'      => $config,
    'actions'     => $__env->make('backend.contents.sender_identities.partials._header-actions', [
                         'model'  => $model,
                         'isView' => $isView,
                     ], \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(),
])
