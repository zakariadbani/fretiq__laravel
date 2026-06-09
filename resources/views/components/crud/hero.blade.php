{{--
    Generic anonymous Blade component: x-crud.hero

    Generalises x-companies.hero for any model.

    Usage:
        x-crud.hero with props: model, title, avatar, badges, subtitle, tiles, statusBar.
        x-slot:actions for action buttons.
        Default slot: tab nav ul (nav nav-stretch nav-line-tabs).

    Props:
        model      (object)       — Eloquent model instance
        title      (string)       — heading text
        avatar     (array|null)   — ['type'=>'initials'|'image','value'=>string,'color'=>string]
        badges     (array)        — [['label','color'], …]
        subtitle   (array)        — [['icon','text','href'?=>string], …]  skip items with empty text
        tiles      (array)        — [['icon','color','value','caption'], …]
        statusBar  (array|null)   — props to spread onto x-crud.status-bar; null = omit

    Slots:
        actions    — action buttons row (top-right of hero)
        $slot      — default: tab nav ul
--}}
@props([
    'model',
    'title'     => '',
    'avatar'    => null,
    'badges'    => [],
    'subtitle'  => [],
    'tiles'     => [],
    'statusBar' => null,
])

<div class="card mb-5 mb-xl-10">
    <div class="card-body pt-9 pb-0">
        {{-- Hero row --}}
        <div class="d-flex flex-wrap flex-sm-nowrap mb-6">

            {{-- Avatar block --}}
            @if($avatar)
                @if(($avatar['type'] ?? 'initials') === 'image' && !empty($avatar['value']))
                    <div class="d-flex flex-center w-100px rounded me-7 align-self-stretch overflow-hidden">
                        <img src="{{ $avatar['value'] }}" class="w-100" alt="{{ $title }}" />
                    </div>
                @else
                    @php
                        $avatarColor   = $avatar['color'] ?? 'primary';
                        $avatarInitial = mb_strtoupper(mb_substr($avatar['value'] ?? $title, 0, 1));
                    @endphp
                    <div class="d-flex flex-center w-100px bg-light-{{ $avatarColor }} rounded me-7 align-self-stretch">
                        <span class="fs-1 fw-bold text-{{ $avatarColor }}">{{ $avatarInitial }}</span>
                    </div>
                @endif
            @endif

            {{-- Content area --}}
            <div class="flex-grow-1">
                <div class="d-flex justify-content-between align-items-start flex-wrap mb-2">
                    <div class="d-flex flex-column">

                        {{-- Title + badges --}}
                        <div class="d-flex align-items-center mb-2">
                            <span class="text-gray-900 fs-2 fw-bold me-2">{{ $title }}</span>
                            @foreach($badges as $badge)
                                <span class="badge badge-light-{{ $badge['color'] ?? 'secondary' }} me-2">{{ $badge['label'] ?? '' }}</span>
                            @endforeach
                        </div>

                        {{-- Subtitle line --}}
                        @php $subtitleItems = array_filter($subtitle, fn($s) => !empty($s['text'])); @endphp
                        @if(!empty($subtitleItems))
                            <div class="d-flex flex-wrap fw-semibold mb-2 fs-6 text-gray-500">
                                @foreach($subtitleItems as $sub)
                                    @if(!empty($sub['href']))
                                        <a href="{{ $sub['href'] }}" class="d-flex align-items-center me-5 mb-2 text-gray-500 text-hover-primary">
                                            <i class="bi {{ $sub['icon'] }} fs-4 me-1"></i>
                                            {{ $sub['text'] }}
                                        </a>
                                    @else
                                        <span class="d-flex align-items-center me-5 mb-2">
                                            <i class="bi {{ $sub['icon'] }} fs-4 me-1"></i>
                                            {{ $sub['text'] }}
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        @endif

                    </div>

                    {{-- Actions slot --}}
                    <div class="d-flex gap-2 mb-2">
                        {{ $actions ?? '' }}
                    </div>
                </div>

                {{-- Status tiles --}}
                @if(!empty($tiles))
                    <div class="d-flex flex-wrap mb-n3">
                        @foreach($tiles as $tile)
                            <x-crud.stat-tile
                                :color="$tile['color'] ?? 'secondary'"
                                :value="$tile['value'] ?? null"
                                :caption="$tile['caption'] ?? ''"
                                :icon="$tile['icon'] ?? null"
                            />
                        @endforeach
                    </div>
                @endif

            </div>
        </div>

        {{-- Status-bar toggle (optional) --}}
        @if(!empty($statusBar))
            <x-crud.status-bar
                :model="$model"
                :field="$statusBar['field'] ?? 'is_active'"
                :route="$statusBar['route']"
                :title="$statusBar['title'] ?? ''"
                :description="$statusBar['description'] ?? ''"
                :permission="$statusBar['permission'] ?? null"
                :success="$statusBar['success'] ?? 'Mis à jour'"
                :error="$statusBar['error'] ?? 'Échec de la mise à jour'"
                :icon="$statusBar['icon'] ?? 'bi-check-circle-fill'"
            />
        @endif

        <div class="separator mb-4"></div>

        {{-- Default slot: tab nav --}}
        {{ $slot }}
    </div>
</div>
