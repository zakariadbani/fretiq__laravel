{{--
    Generic anonymous Blade component: one <tr> for the aperçu details table.

    Usage examples:
        <x-crud.detail-row label="Secteur" :value="$model->sector" />
        <x-crud.detail-row label="Relation" :value="$model->relationship" type="enum" configKey="company_relationships" />
        <x-crud.detail-row label="Score IA" :value="$model->ai_score" type="score" />
        <x-crud.detail-row label="Actif" :value="$model->is_active" type="boolean" />
        <x-crud.detail-row label="Créé le" :value="$model->created_at" type="date" />
        <x-crud.detail-row label="Email" :value="$model->email" type="email" />
        <x-crud.detail-row label="Tags" :value="$model->tags" type="tags" color="primary" />
        <x-crud.detail-row label="Statut actif" :value="$model->is_active" type="boolean" />
        <x-crud.detail-row label="Site" :value="$model->domain" type="link" :href="'https://'.$model->domain" />
        <x-crud.detail-row label="Note" type="raw">{{ $model->note }}</x-crud.detail-row>

    Types:
        text      — plain escaped text; muted $empty if blank/null
        badge     — <span class="badge badge-light-{color}">value</span>
        enum      — resolve config('global.data.{configKey}.{value}') → badge; muted dash if missing
        link      — <a href="$href">value</a>
        email     — <a href="mailto:value">value</a>
        date      — format as d/m/Y H:i (handles Carbon or date string); muted dash if null
        boolean   — success "Actif" badge / secondary "Inactif" badge
        tags      — array of strings → badge chips (badge-light-{color})
        score     — renders <x-crud.score :value="$value" />
        raw       — outputs $slot (use <x-slot:slot> or default slot body)

    Props:
        label     (string)           — row label text
        value     (*|null)           — field value; meaning depends on type
        type      (string)           — one of the type keywords above; default 'text'
        configKey (string|null)      — config path segment for enum type
        color     (string|null)      — badge/tags color token; default 'secondary'
        href      (string|null)      — URL for link type
        empty     (string)           — fallback text for null/blank; default '—'
--}}
@props([
    'label',
    'value'     => null,
    'type'      => 'text',
    'configKey' => null,
    'color'     => 'secondary',
    'href'      => null,
    'empty'     => '—',
])

<tr>
    <td class="text-muted fw-semibold w-50">{{ $label }}</td>
    <td>
        @switch($type)

            @case('badge')
                @if(isset($value) && $value !== '' && $value !== null)
                    <span class="badge badge-light-{{ $color }}">{{ $value }}</span>
                @else
                    <span class="text-muted">{{ $empty }}</span>
                @endif
                @break

            @case('enum')
                @php
                    $enumCfg = ($configKey && $value !== null && $value !== '')
                        ? config('global.data.' . $configKey . '.' . $value)
                        : null;
                @endphp
                @if($enumCfg)
                    <span class="badge badge-light-{{ $enumCfg['color'] ?? 'secondary' }}">{{ $enumCfg['label'] ?? $value }}</span>
                @else
                    <span class="text-muted">{{ $empty }}</span>
                @endif
                @break

            @case('link')
                @if(isset($value) && $value !== '' && $value !== null && $href)
                    <a href="{{ $href }}" target="_blank" rel="noopener noreferrer" class="text-gray-800 fw-bold text-hover-primary">{{ $value }}</a>
                @elseif(isset($value) && $value !== '' && $value !== null)
                    <span class="text-gray-800 fw-bold">{{ $value }}</span>
                @else
                    <span class="text-muted">{{ $empty }}</span>
                @endif
                @break

            @case('email')
                @if(isset($value) && $value !== '' && $value !== null)
                    <a href="mailto:{{ $value }}" class="text-gray-800 fw-bold text-hover-primary">{{ $value }}</a>
                @else
                    <span class="text-muted">{{ $empty }}</span>
                @endif
                @break

            @case('date')
                @php
                    $formatted = null;
                    if ($value !== null) {
                        try {
                            $formatted = ($value instanceof \Carbon\Carbon)
                                ? $value->format('d/m/Y H:i')
                                : \Carbon\Carbon::parse($value)->format('d/m/Y H:i');
                        } catch (\Throwable $e) {
                            $formatted = null;
                        }
                    }
                @endphp
                @if($formatted)
                    <span class="text-gray-800 fw-bold">{{ $formatted }}</span>
                @else
                    <span class="text-muted">{{ $empty }}</span>
                @endif
                @break

            @case('boolean')
                @if($value)
                    <span class="badge badge-light-success">Actif</span>
                @else
                    <span class="badge badge-light-secondary">Inactif</span>
                @endif
                @break

            @case('tags')
                @php $tags = is_array($value) ? $value : (is_string($value) ? array_filter(array_map('trim', explode(',', $value))) : []); @endphp
                @if(!empty($tags))
                    @foreach($tags as $tag)
                        <span class="badge badge-light-{{ $color }} me-1">{{ $tag }}</span>
                    @endforeach
                @else
                    <span class="text-muted">{{ $empty }}</span>
                @endif
                @break

            @case('score')
                <x-crud.score :value="$value" />
                @break

            @case('raw')
                {{ $slot }}
                @break

            @default
                {{-- text --}}
                @if(isset($value) && $value !== '' && $value !== null)
                    <span class="text-gray-800 fw-bold">{{ $value }}</span>
                @else
                    <span class="text-muted">{{ $empty }}</span>
                @endif

        @endswitch
    </td>
</tr>
