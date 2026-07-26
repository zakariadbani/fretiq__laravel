@php
    $isView = ($currentPage ?? 'view') === 'view';
    $config = $viewConfig ?? \App\Crud\ViewConfigs\SuppressionViewConfig::make($model);
@endphp

@include('backend.partials.crud._tabbar', [
    'model'       => $model,
    'currentPage' => $currentPage ?? 'view',
    'config'      => $config,
    'actions'     => $__env->make('backend.contents.suppressions.partials._header-actions', [
                         'model'  => $model,
                         'isView' => $isView,
                     ], \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(),
])
