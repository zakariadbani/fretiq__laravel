{{--
    Contacts pane — hybrid smart-list management for a segment.
    Ships an empty shell + skeleton; rows load lazily via IntersectionObserver
    on first scroll-into-view (segment-contacts.js).

    B1 CRITICAL: NO name= attributes anywhere in this pane. FormData(form)
    serializes by name — a stray name= would submit segment data.
    All controls use id / data-* / class only.
--}}

<div class="card">
    <div class="card-header border-0 pt-5">
        <h3 class="card-title fw-bolder m-0">
            <i class="bi bi-people text-info fs-3 me-2"></i>
            Contacts
            <span class="badge badge-light-primary ms-2 fs-7 fw-semibold"
                  id="segment_contacts_count">
                {{ $stats['contacts_count'] ?? '' }}
            </span>
        </h3>
        @can('edit segments')
            <div class="card-toolbar">
                <button type="button"
                        class="btn btn-sm btn-light-primary"
                        data-bs-toggle="modal"
                        data-bs-target="#segment_contacts_modal"
                        id="btn_add_segment_contact"
                        aria-label="Ajouter des contacts à ce segment">
                    <i class="bi bi-plus fs-4 me-1"></i>
                    Ajouter des contacts
                </button>
            </div>
        @endcan
    </div>

    <div class="card-body border-top p-0">
        {{--
            #segment_contacts_wrapper: the JS target for lazy-load and fragment swaps.
            data-list-url drives the GET contacts endpoint.
        --}}
        <div id="segment_contacts_wrapper"
             data-list-url="{{ route('admin.segments.contacts', $model->id) }}">

            {{-- Skeleton placeholder — shown before first lazy-load --}}
            <div id="segment_contacts_skeleton" class="p-7">
                <div class="d-flex flex-column gap-4">
                    @for($i = 0; $i < 5; $i++)
                        <div class="d-flex align-items-center gap-4">
                            <div class="rounded bg-gray-200 flex-grow-1" style="height:16px; max-width:180px;"></div>
                            <div class="rounded bg-gray-200" style="height:16px; width:140px;"></div>
                            <div class="rounded bg-gray-200" style="height:16px; width:100px;"></div>
                            <div class="rounded bg-gray-200 ms-auto" style="height:28px; width:80px;"></div>
                        </div>
                    @endfor
                </div>
                <p class="text-muted fs-8 mt-4 mb-0 text-center">Chargement des contacts…</p>
            </div>

        </div>
    </div>
</div>
