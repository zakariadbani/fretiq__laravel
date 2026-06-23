{{--
    Contacts rows fragment — rendered by the contacts AJAX endpoint + swapped by JS.
    Receives:
        $segment          — Segment model
        $paginator        — LengthAwarePaginator of resolved Contact models (company loaded)
        $excludedContacts — Collection of Contact (company loaded), manually excluded
        $counts           — ['contacts_count'=>int, 'funnel'=>[...], 'pinned_in'=>int, 'pinned_out'=>int]
        $provenance       — map contact_id => 'pinned'|'filter'

    B1: ZERO name= attributes. All hooks are id/data-*/class only.
    D2: Provenance badges — text + color, never color alone.
    D3: Responsive — table on ≥576px, stacked cards on <576px.
    D4: "X exclus" chip that expands the excluded list.
--}}

@php
    $pinnedOut = $counts['pinned_out'] ?? 0;
    $contactStatuses = config('global.data.contact_statuses', []);
@endphp

{{-- ── "X exclus" chip (D4) ──────────────────────────────────────────── --}}
@if($pinnedOut > 0)
    <div class="px-7 pt-5 pb-2">
        <button type="button"
                class="btn btn-sm btn-light-secondary d-flex align-items-center gap-2"
                id="segment_excluded_toggle"
                aria-expanded="false"
                aria-controls="segment_excluded_list">
            <i class="bi bi-eye-slash fs-6"></i>
            <span>{{ $pinnedOut }} exclu{{ $pinnedOut > 1 ? 's' : '' }} manuellement</span>
            <i class="bi bi-chevron-down fs-8 ms-1" id="segment_excluded_chevron"></i>
        </button>

        {{-- Excluded list (hidden by default) --}}
        <div id="segment_excluded_list" class="d-none mt-3">

            {{-- Desktop table for excluded --}}
            <div class="d-none d-sm-block">
                <table class="table table-row-dashed table-row-gray-200 align-middle fs-7 gy-2 mb-3">
                    <thead>
                        <tr class="fw-semibold text-muted">
                            <th class="min-w-140px">Nom</th>
                            <th class="min-w-120px">Email</th>
                            <th class="min-w-100px">Société</th>
                            <th class="min-w-80px">Provenance</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($excludedContacts as $exc)
                            @php $excStatusCfg = $contactStatuses[$exc->status] ?? []; @endphp
                            <tr>
                                <td>
                                    <span class="text-gray-800 fw-semibold fs-7">{{ $exc->name }}</span>
                                    @if($exc->position)
                                        <span class="text-muted d-block fs-8">{{ $exc->position }}</span>
                                    @endif
                                </td>
                                <td class="text-gray-700 fs-7">{{ $exc->email }}</td>
                                <td class="text-gray-700 fs-7">{{ $exc->company->name ?? '—' }}</td>
                                <td>
                                    <span class="badge badge-light-secondary">
                                        <i class="bi bi-dash-circle me-1 fs-8"></i>
                                        Exclu
                                    </span>
                                </td>
                                <td class="text-end">
                                    @can('edit segments')
                                        <button type="button"
                                                class="btn btn-sm btn-light-success btn-segment-reinclude"
                                                data-contact-id="{{ $exc->id }}"
                                                data-unpin-url="{{ route('admin.segments.contacts.unpin', [$segment->id, $exc->id]) }}"
                                                aria-label="Réinclure {{ $exc->name }}">
                                            <i class="bi bi-plus-circle me-1 fs-7"></i>
                                            Réinclure
                                        </button>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-muted text-center py-4">Aucun contact exclu.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Mobile stacked cards for excluded (D3) --}}
            <div class="d-sm-none">
                @forelse($excludedContacts as $exc)
                    <div class="border rounded p-3 mb-2">
                        <div class="fw-semibold text-gray-800 fs-7">{{ $exc->name }}</div>
                        <div class="text-muted fs-8">{{ $exc->email }}</div>
                        <div class="text-muted fs-8">{{ $exc->company->name ?? '—' }}</div>
                        <div class="d-flex align-items-center justify-content-between mt-2">
                            <span class="badge badge-light-secondary">
                                <i class="bi bi-dash-circle me-1 fs-8"></i>
                                Exclu
                            </span>
                            @can('edit segments')
                                <button type="button"
                                        class="btn btn-sm btn-light-success btn-segment-reinclude"
                                        style="min-height:44px;"
                                        data-contact-id="{{ $exc->id }}"
                                        data-unpin-url="{{ route('admin.segments.contacts.unpin', [$segment->id, $exc->id]) }}"
                                        aria-label="Réinclure {{ $exc->name }}">
                                    <i class="bi bi-plus-circle me-1 fs-7"></i>
                                    Réinclure
                                </button>
                            @endcan
                        </div>
                    </div>
                @empty
                    <p class="text-muted fs-8 text-center py-3">Aucun contact exclu.</p>
                @endforelse
            </div>

        </div>
    </div>
@endif

{{-- ── Main contacts table ─────────────────────────────────────────────── --}}
@if($paginator->total() === 0)
    {{-- Empty state --}}
    <div class="text-center py-12 text-muted px-7">
        <i class="bi bi-people fs-2x mb-3 d-block text-gray-400"></i>
        <p class="fw-semibold mb-1">Aucun contact dans cette audience.</p>
        <p class="fs-8 mb-0">Élargissez le Ciblage ou ajoutez des contacts manuellement.</p>
    </div>
@else

    {{-- Desktop table (≥576px) --}}
    <div class="d-none d-sm-block">
        <div class="table-responsive px-7 pt-4">
            <table class="table table-row-bordered table-row-gray-200 align-middle gs-0 gy-3 fs-7">
                <thead>
                    <tr class="fw-semibold text-muted">
                        <th class="min-w-140px">Nom</th>
                        <th class="min-w-120px">Email</th>
                        <th class="min-w-100px">Société</th>
                        <th class="min-w-80px">Provenance</th>
                        <th class="min-w-80px">Statut</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($paginator->items() as $contact)
                        @php
                            $prov       = $provenance[$contact->id] ?? 'filter';
                            $statusCfg  = $contactStatuses[$contact->status] ?? [];
                        @endphp
                        <tr>
                            <td>
                                <a href="{{ route('admin.contacts.view', $contact->id) }}"
                                   class="text-gray-900 fw-semibold text-hover-primary fs-7">
                                    {{ $contact->name }}
                                </a>
                                @if($contact->position)
                                    <span class="text-muted d-block fs-8">{{ $contact->position }}</span>
                                @endif
                            </td>
                            <td>
                                <a href="mailto:{{ $contact->email }}"
                                   class="text-gray-700 text-hover-primary fs-7">
                                    {{ $contact->email }}
                                </a>
                            </td>
                            <td class="text-gray-700 fs-7">{{ $contact->company->name ?? '—' }}</td>
                            <td>
                                @if($prov === 'pinned')
                                    <span class="badge badge-light-primary">
                                        <i class="bi bi-pin-fill me-1 fs-8"></i>
                                        Épinglé
                                    </span>
                                @else
                                    <span class="badge badge-light-info">
                                        <i class="bi bi-funnel me-1 fs-8"></i>
                                        Filtre
                                    </span>
                                @endif
                            </td>
                            <td>
                                @if($statusCfg)
                                    <span class="badge badge-light-{{ $statusCfg['color'] }}">{{ $statusCfg['label'] }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @can('edit segments')
                                    @if($prov === 'pinned')
                                        <button type="button"
                                                class="btn btn-sm btn-light-warning btn-segment-unpin"
                                                data-contact-id="{{ $contact->id }}"
                                                data-unpin-url="{{ route('admin.segments.contacts.unpin', [$segment->id, $contact->id]) }}"
                                                aria-label="Retirer l'épingle de {{ $contact->name }}">
                                            <i class="bi bi-pin-angle me-1 fs-7"></i>
                                            Retirer l'épingle
                                        </button>
                                    @else
                                        <button type="button"
                                                class="btn btn-sm btn-light-danger btn-segment-exclude"
                                                data-contact-id="{{ $contact->id }}"
                                                data-pin-url="{{ route('admin.segments.contacts.pin', $segment->id) }}"
                                                aria-label="Exclure {{ $contact->name }}">
                                            <i class="bi bi-dash-circle me-1 fs-7"></i>
                                            Exclure
                                        </button>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Mobile stacked cards (<576px, D3) --}}
    <div class="d-sm-none px-5 pt-4">
        @foreach($paginator->items() as $contact)
            @php
                $prov      = $provenance[$contact->id] ?? 'filter';
                $statusCfg = $contactStatuses[$contact->status] ?? [];
            @endphp
            <div class="border rounded p-3 mb-3">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <a href="{{ route('admin.contacts.view', $contact->id) }}"
                           class="fw-semibold text-gray-900 text-hover-primary fs-7">
                            {{ $contact->name }}
                        </a>
                        <div class="text-muted fs-8">{{ $contact->email }}</div>
                        <div class="text-muted fs-8">{{ $contact->company->name ?? '—' }}</div>
                    </div>
                    <div class="d-flex flex-column gap-1 align-items-end">
                        @if($prov === 'pinned')
                            <span class="badge badge-light-primary">
                                <i class="bi bi-pin-fill me-1 fs-8"></i>Épinglé
                            </span>
                        @else
                            <span class="badge badge-light-info">
                                <i class="bi bi-funnel me-1 fs-8"></i>Filtre
                            </span>
                        @endif
                        @if($statusCfg)
                            <span class="badge badge-light-{{ $statusCfg['color'] }}">{{ $statusCfg['label'] }}</span>
                        @endif
                    </div>
                </div>
                @can('edit segments')
                    <div class="mt-2">
                        @if($prov === 'pinned')
                            <button type="button"
                                    class="btn btn-sm btn-light-warning w-100 btn-segment-unpin"
                                    style="min-height:44px;"
                                    data-contact-id="{{ $contact->id }}"
                                    data-unpin-url="{{ route('admin.segments.contacts.unpin', [$segment->id, $contact->id]) }}"
                                    aria-label="Retirer l'épingle de {{ $contact->name }}">
                                <i class="bi bi-pin-angle me-1 fs-7"></i>
                                Retirer l'épingle
                            </button>
                        @else
                            <button type="button"
                                    class="btn btn-sm btn-light-danger w-100 btn-segment-exclude"
                                    style="min-height:44px;"
                                    data-contact-id="{{ $contact->id }}"
                                    data-pin-url="{{ route('admin.segments.contacts.pin', $segment->id) }}"
                                    aria-label="Exclure {{ $contact->name }}">
                                <i class="bi bi-dash-circle me-1 fs-7"></i>
                                Exclure
                            </button>
                        @endif
                    </div>
                @endcan
            </div>
        @endforeach
    </div>

    {{-- Pagination --}}
    <div class="px-7 pb-5 pt-3 d-flex justify-content-center">
        {{-- Intercept pagination clicks via .segment-contacts-page (JS delegated) --}}
        <div class="segment-pagination-wrap">
            @if($paginator->hasPages())
                {{-- Render standard paginator but wrap links to be intercepted by JS --}}
                <nav aria-label="Navigation des contacts">
                    <ul class="pagination pagination-sm mb-0">
                        {{-- Previous --}}
                        @if($paginator->onFirstPage())
                            <li class="page-item disabled">
                                <span class="page-link"><i class="bi bi-chevron-left"></i></span>
                            </li>
                        @else
                            <li class="page-item">
                                <button type="button"
                                        class="page-link segment-contacts-page"
                                        data-page="{{ $paginator->currentPage() - 1 }}"
                                        aria-label="Page précédente">
                                    <i class="bi bi-chevron-left"></i>
                                </button>
                            </li>
                        @endif

                        {{-- Page numbers --}}
                        @foreach($paginator->getUrlRange(max(1, $paginator->currentPage()-2), min($paginator->lastPage(), $paginator->currentPage()+2)) as $page => $url)
                            <li class="page-item {{ $page === $paginator->currentPage() ? 'active' : '' }}">
                                <button type="button"
                                        class="page-link segment-contacts-page"
                                        data-page="{{ $page }}">
                                    {{ $page }}
                                </button>
                            </li>
                        @endforeach

                        {{-- Next --}}
                        @if($paginator->hasMorePages())
                            <li class="page-item">
                                <button type="button"
                                        class="page-link segment-contacts-page"
                                        data-page="{{ $paginator->currentPage() + 1 }}"
                                        aria-label="Page suivante">
                                    <i class="bi bi-chevron-right"></i>
                                </button>
                            </li>
                        @else
                            <li class="page-item disabled">
                                <span class="page-link"><i class="bi bi-chevron-right"></i></span>
                            </li>
                        @endif
                    </ul>
                </nav>
                <p class="text-muted fs-8 text-center mt-2 mb-0">
                    {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} sur {{ $paginator->total() }} contacts
                </p>
            @endif
        </div>
    </div>

@endif
