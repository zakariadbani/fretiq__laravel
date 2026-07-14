@php($checklist = $checklist ?? ['items' => [], 'complete' => false])

<div class="card mb-8">
    <div class="card-header border-0 pt-6">
        <div class="card-title flex-column align-items-start">
            <h3 class="fw-bold mb-1">Première campagne : ordre de préparation</h3>
            <span class="text-muted fs-7">Avancez dans cet ordre pour éviter un envoi bloqué au dernier moment.</span>
        </div>
    </div>
    <div class="card-body pt-0">
        <div class="d-flex flex-column gap-4">
            @foreach ($checklist['items'] as $index => $item)
                <div class="d-flex flex-column flex-md-row align-items-md-center gap-3 py-3 border-bottom border-gray-200">
                    <div class="symbol symbol-36px flex-shrink-0">
                        <div class="symbol-label bg-light-{{ $item['complete'] ? 'success' : 'primary' }}">
                            @if ($item['complete'])
                                <i class="bi bi-check-lg text-success fs-4"></i>
                            @else
                                <span class="fw-bold text-primary">{{ $index + 1 }}</span>
                            @endif
                        </div>
                    </div>
                    <div class="flex-grow-1">
                        <div class="fw-bold text-gray-900">{{ $item['label'] }}</div>
                        <div class="text-muted fs-7">{{ $item['detail'] }}</div>
                    </div>
                    @if ($item['url'] && $item['action'])
                        <a href="{{ $item['url'] }}" class="btn btn-sm btn-light-primary align-self-start align-self-md-center">
                            {{ $item['action'] }}
                        </a>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</div>
