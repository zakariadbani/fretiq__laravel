@props(['items' => []])

<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
    <li class="breadcrumb-item text-muted">
        <a href="{{ route('admin.dashboard') }}" class="text-muted text-hover-primary">Accueil</a>
    </li>
    @foreach ($items as $item)
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        @if (!empty($item['route']))
            <li class="breadcrumb-item text-muted">
                <a href="{{ route($item['route']) }}" class="text-muted text-hover-primary">{{ $item['label'] }}</a>
            </li>
        @elseif (!empty($item['url']))
            <li class="breadcrumb-item text-muted">
                <a href="{{ $item['url'] }}" class="text-muted text-hover-primary">{{ $item['label'] }}</a>
            </li>
        @else
            <li class="breadcrumb-item text-muted">{{ $item['label'] }}</li>
        @endif
    @endforeach
</ul>
