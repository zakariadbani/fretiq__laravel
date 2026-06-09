{{--
    Anonymous Blade component: <x-companies.hero :model="$model">
        <x-slot:actions>…buttons…</x-slot:actions>
    </x-companies.hero>

    Renders the Metronic hero header card for the company view and edit pages.
    Mirror of prototype/company-view.html hero section (card mb-5 mb-xl-10).
--}}
@props(['model'])

@php
    $initial  = mb_strtoupper(mb_substr($model->name ?? '', 0, 1));
    $relCfg   = config('global.data.company_relationships.' . $model->relationship, []);
    $relLabel = $relCfg['label'] ?? null;
    $relColor = $relCfg['color'] ?? 'secondary';
    $score    = $model->ai_score;
    $scoreColor = $score !== null
        ? ($score >= 70 ? 'success' : ($score >= 40 ? 'warning' : 'danger'))
        : null;
@endphp

<div class="card mb-5 mb-xl-10">
    <div class="card-body pt-9 pb-0">
        {{-- Hero row --}}
        <div class="d-flex flex-wrap flex-sm-nowrap mb-6">
            <div class="me-7 mb-4">
                <div class="symbol symbol-100px symbol-fixed position-relative">
                    <div class="symbol-label fs-1 bg-light-primary text-primary fw-bold">{{ $initial }}</div>
                </div>
            </div>
            <div class="flex-grow-1">
                <div class="d-flex justify-content-between align-items-start flex-wrap mb-2">
                    <div class="d-flex flex-column">
                        <div class="d-flex align-items-center mb-2">
                            <span class="text-gray-900 fs-2 fw-bold me-2">{{ $model->name }}</span>
                            @if($relLabel)
                                <span class="badge badge-light-{{ $relColor }} me-2">{{ $relLabel }}</span>
                            @endif
                            @if($model->domain)
                                <a href="https://{{ $model->domain }}" target="_blank" rel="noopener noreferrer"
                                   class="d-flex align-items-center text-gray-500 text-hover-primary ms-1">
                                    <i class="bi bi-globe fs-5 me-1"></i>
                                    {{ $model->domain }}
                                </a>
                            @endif
                        </div>
                        <div class="d-flex flex-wrap fw-semibold mb-2 fs-6 text-gray-500">
                            @if($model->sector)
                                <span class="d-flex align-items-center me-5 mb-2">
                                    <i class="bi bi-briefcase fs-4 me-1"></i>
                                    {{ $model->sector }}
                                </span>
                            @endif
                            @if($model->country)
                                <span class="d-flex align-items-center me-5 mb-2">
                                    <i class="bi bi-geo-alt fs-4 me-1"></i>
                                    {{ strtoupper($model->country) }}
                                </span>
                            @endif
                            @if($score !== null)
                                <span class="d-flex align-items-center mb-2">
                                    <i class="bi bi-graph-up fs-4 me-1"></i>
                                    Score IA : <span class="text-{{ $scoreColor }} fw-bold ms-1">{{ $score }}</span>
                                </span>
                            @endif
                        </div>
                    </div>
                    {{-- Slot for action buttons (Modifier / Retour / Annuler etc.) --}}
                    <div class="d-flex my-4 gap-2">
                        {{ $actions ?? '' }}
                    </div>
                </div>
            </div>
        </div>
        {{-- Tab nav slot: the caller adds <ul class="nav nav-stretch nav-line-tabs ..."> --}}
        {{ $slot }}
    </div>
</div>
