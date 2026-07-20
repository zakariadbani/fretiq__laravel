@php($checklist = $checklist ?? ['items' => [], 'complete' => false])

<div class="card mb-6">
    <div class="card-body py-5">
        <div class="mb-3">
            <h3 class="fw-bold mb-1">Première campagne : ordre de préparation</h3>
            <span class="text-muted fs-7">Avancez dans cet ordre pour éviter un envoi bloqué au dernier moment.</span>
        </div>
        <div class="d-flex flex-column">
            @foreach ($checklist['items'] as $index => $item)
                <div class="d-flex align-items-center gap-3 py-2{{ $loop->last ? '' : ' border-bottom border-gray-200' }}">
                    <div class="symbol symbol-30px flex-shrink-0">
                        <div class="symbol-label bg-light-{{ $item['complete'] ? 'success' : 'primary' }}">
                            @if ($item['complete'])
                                <i class="bi bi-check-lg text-success fs-5"></i>
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
                        <a href="{{ $item['url'] }}" class="btn btn-sm btn-light-primary flex-shrink-0">
                            {{ $item['action'] }}
                        </a>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</div>
