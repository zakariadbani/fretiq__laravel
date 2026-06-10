<x-default-layout>

@section('title')
    Planning des campagnes
@endsection

@section('breadcrumbs')
    <ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
        <li class="breadcrumb-item text-muted">
            <a href="{{ route('admin.dashboard') }}" class="text-muted text-hover-primary">Accueil</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">Campagnes</li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">Planning</li>
    </ul>
@endsection

{{-- Carte calendrier Metronic : header avec légende des statuts depuis config --}}
<div class="card">
    <div class="card-header">
        <h2 class="card-title fw-bold">Planning des campagnes</h2>
        <div class="card-toolbar">
            <div class="d-flex flex-wrap align-items-center gap-4">
                @foreach (config('global.data.campaign_run_statuses', []) as $status)
                    <div class="d-flex align-items-center gap-1">
                        <span class="w-10px h-10px rounded-1 bg-{{ $status['color'] }} d-inline-block"></span>
                        <span class="text-muted fs-7 fw-semibold">{{ $status['label'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
    <div class="card-body">
        <div id="kt_calendar_app"></div>
    </div>
</div>

{{-- Modal lecture seule : détail d'un événement au clic --}}
<div class="modal fade" id="kt_modal_view_event" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered mw-650px">
        <div class="modal-content">
            <div class="modal-header border-0 justify-content-end pb-0">
                <div class="btn btn-icon btn-sm btn-active-icon-primary" data-bs-dismiss="modal" aria-label="Fermer">
                    <i class="ki-duotone ki-cross fs-2x"><span class="path1"></span><span class="path2"></span></i>
                </div>
            </div>
            <div class="modal-body pt-0 pb-15 px-lg-17">
                <div class="d-flex align-items-center mb-7">
                    <i class="ki-duotone ki-calendar-8 fs-1 text-muted me-5"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span><span class="path5"></span><span class="path6"></span></i>
                    <div>
                        <div class="d-flex align-items-center mb-1">
                            <span class="fs-3 fw-bold me-3" data-kt-planner="event_name"></span>
                            <span class="badge" data-kt-planner="event_status"></span>
                        </div>
                        <div class="fs-6 text-muted" data-kt-planner="event_start"></div>
                    </div>
                </div>
                <div class="d-flex justify-content-end">
                    <a href="#" class="btn btn-primary" data-kt-planner="event_link">Voir la campagne</a>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
"use strict";

(function () {
    var calendarEl = document.getElementById('kt_calendar_app');
    if (!calendarEl || typeof FullCalendar === 'undefined') { return; }

    var modalEl = document.getElementById('kt_modal_view_event');
    var modal = modalEl ? new bootstrap.Modal(modalEl) : null;

    var calendar = new FullCalendar.Calendar(calendarEl, {
        headerToolbar: {
            left:   'prev,next today',
            center: 'title',
            right:  'dayGridMonth,timeGridWeek,timeGridDay'
        },
        initialView: 'dayGridMonth',
        locale: 'fr',
        buttonText: {
            today:  "Aujourd'hui",
            month:  'Mois',
            week:   'Semaine',
            day:    'Jour'
        },
        events: '{{ route('admin.planner.feed') }}',
        editable: false,
        selectable: false,
        navLinks: true,
        dayMaxEvents: true,
        height: 800,
        eventClick: function (info) {
            info.jsEvent.preventDefault();
            if (!modal) { return; }

            var props = info.event.extendedProps || {};

            modalEl.querySelector('[data-kt-planner="event_name"]').textContent = info.event.title;

            var statusEl = modalEl.querySelector('[data-kt-planner="event_status"]');
            statusEl.className = 'badge badge-light-' + (props.statusColor || 'secondary');
            statusEl.textContent = props.statusLabel || '';

            modalEl.querySelector('[data-kt-planner="event_start"]').textContent = info.event.start
                ? info.event.start.toLocaleString('fr-FR', { dateStyle: 'full', timeStyle: 'short' })
                : '';

            var linkEl = modalEl.querySelector('[data-kt-planner="event_link"]');
            if (info.event.url) {
                linkEl.href = info.event.url;
                linkEl.classList.remove('d-none');
            } else {
                linkEl.classList.add('d-none');
            }

            modal.show();
        }
    });

    calendar.render();
}());
</script>
@endpush

</x-default-layout>
