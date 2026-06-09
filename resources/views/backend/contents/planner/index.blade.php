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

{{-- Légende des statuts --}}
<div class="d-flex align-items-center gap-3 mb-5">
    <div class="d-flex align-items-center gap-1">
        <span class="w-10px h-10px rounded-1 bg-primary d-inline-block"></span>
        <span class="text-muted fs-7 fw-semibold">Planifiée</span>
    </div>
    <div class="d-flex align-items-center gap-1">
        <span class="w-10px h-10px rounded-1 bg-success d-inline-block"></span>
        <span class="text-muted fs-7 fw-semibold">Envoyée</span>
    </div>
    <div class="d-flex align-items-center gap-1">
        <span class="w-10px h-10px rounded-1 bg-warning d-inline-block"></span>
        <span class="text-muted fs-7 fw-semibold">En pause</span>
    </div>
    <div class="d-flex align-items-center gap-1">
        <span class="w-10px h-10px rounded-1 bg-danger d-inline-block"></span>
        <span class="text-muted fs-7 fw-semibold">Échouée</span>
    </div>
</div>

{{-- Calendrier FullCalendar --}}
<div class="card">
    <div class="card-body p-6">
        <div id="kt_calendar"></div>
    </div>
</div>

@push('styles')
<link rel="stylesheet" href="{{ asset('assets/plugins/custom/fullcalendar/fullcalendar.bundle.css') }}" />
@endpush

@push('scripts')
<script src="{{ asset('assets/plugins/custom/fullcalendar/fullcalendar.bundle.js') }}"></script>
<script>
"use strict";

(function () {
    var calendarEl = document.getElementById('kt_calendar');
    if (!calendarEl || typeof FullCalendar === 'undefined') { return; }

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
        dayMaxEvents: true,
        weekends: true,
        height: 700,
        eventClick: function (info) {
            if (info.event.url) {
                info.jsEvent.preventDefault();
                window.location = info.event.url;
            }
        },
        eventContent: function (info) {
            var dot = '<span class="bullet bullet-dot h-6px w-6px me-1" style="background:' + (info.event.backgroundColor || '#009EF7') + '"></span>';
            return {
                html: '<div class="d-flex align-items-center px-1 py-0" style="overflow:hidden;white-space:nowrap;">'
                    + dot
                    + '<span class="fw-semibold fs-7" style="overflow:hidden;text-overflow:ellipsis;">'
                    + info.event.title
                    + '</span></div>'
            };
        }
    });

    calendar.render();
}());
</script>
@endpush

</x-default-layout>
