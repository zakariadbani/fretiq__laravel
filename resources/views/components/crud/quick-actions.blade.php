{{--
    Generic anonymous Blade component: quick-actions card.

    Usage:
        <x-crud.quick-actions title="Actions rapides" :actions="[
            ['label'=>'Modifier', 'icon'=>'bi-pencil', 'color'=>'light-primary', 'permission'=>'edit companies', 'href'=>route('admin.companies.edit',$model)],
            ['label'=>'Supprimer', 'icon'=>'bi-trash', 'color'=>'light-danger', 'permission'=>'delete companies', 'onclick'=>'confirmDelete()'],
        ]" />

    Action item shape (all optional except label):
        label      (string)       — button text
        icon       (string|null)  — Bootstrap Icons class (e.g. 'bi-pencil')
        color      (string)       — Bootstrap button variant; default 'light-primary'
        permission (string|null)  — Spatie permission; null = always show
        href       (string|null)  — if set renders an <a>; null with onclick renders <button>
        onclick    (string|null)  — JS expression for onclick attribute
        attrs      (array)        — extra HTML attributes merged onto the element

    Rules:
        - Actions with permission set are only rendered when @can passes.
        - Actions with a null href AND null onclick are rendered as a disabled <button>.
        - If NO actions survive filtering, the entire card is not rendered.

    Props:
        actions (array)   — list of action item arrays (see shape above)
        title   (string)  — card title; default 'Actions rapides'
--}}
@props([
    'actions' => [],
    'title'   => 'Actions rapides',
])

@php
    // Pre-filter actions by permission using Auth::user()->can() so we can gate the card wrapper.
    $visibleActions = [];
    foreach ($actions as $action) {
        $perm = $action['permission'] ?? null;
        if ($perm === null) {
            $visibleActions[] = $action;
        } elseif (auth()->check() && auth()->user()->can($perm)) {
            $visibleActions[] = $action;
        }
    }
@endphp

@if(!empty($visibleActions))
    <div class="card mb-5">
        <div class="card-header border-0 pt-5">
            <h3 class="card-title fs-5 fw-bold">{{ $title }}</h3>
        </div>
        <div class="card-body pt-0 d-flex flex-column gap-3">
            @foreach($visibleActions as $action)
                @php
                    $btnColor  = $action['color']   ?? 'light-primary';
                    $btnIcon   = $action['icon']     ?? null;
                    $btnLabel  = $action['label']    ?? '';
                    $btnHref   = $action['href']     ?? null;
                    $btnClick  = $action['onclick']  ?? null;
                    $btnAttrs  = $action['attrs']    ?? [];

                    // Build extra attrs string — keys/values are trusted (developer-supplied).
                    $attrsStr = '';
                    foreach ($btnAttrs as $attrKey => $attrVal) {
                        $attrsStr .= ' ' . htmlspecialchars($attrKey, ENT_QUOTES, 'UTF-8')
                                   . '="' . htmlspecialchars((string)$attrVal, ENT_QUOTES, 'UTF-8') . '"';
                    }
                @endphp

                @if($btnHref)
                    <a href="{{ $btnHref }}" class="btn btn-{{ $btnColor }} w-100 text-start" {!! $attrsStr !!}>
                        @if($btnIcon)<i class="bi {{ $btnIcon }} me-1"></i>@endif
                        {{ $btnLabel }}
                    </a>
                @elseif($btnClick)
                    <button type="button" class="btn btn-{{ $btnColor }} w-100 text-start" onclick="{{ $btnClick }}" {!! $attrsStr !!}>
                        @if($btnIcon)<i class="bi {{ $btnIcon }} me-1"></i>@endif
                        {{ $btnLabel }}
                    </button>
                @else
                    <button type="button" class="btn btn-{{ $btnColor }} w-100 text-start" disabled {!! $attrsStr !!}>
                        @if($btnIcon)<i class="bi {{ $btnIcon }} me-1"></i>@endif
                        {{ $btnLabel }}
                    </button>
                @endif
            @endforeach
        </div>
    </div>
@endif
