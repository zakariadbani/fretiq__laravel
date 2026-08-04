<x-default-layout>

@section('title')
    Planning
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Planning']]" />
@endsection

{{-- Carte calendrier Metronic : header avec légende des statuts depuis config --}}
<div class="card">
    <div class="card-header">
        <h2
            id="planner-current-time"
            class="card-title fw-bold"
            data-timezone="{{ $plannerTimezone }}"
        >{{ now($plannerTimezone)->format('H:i:s') }} ({{ $plannerTimezone }})</h2>
        <div class="card-toolbar">
            <div class="d-flex flex-wrap align-items-center gap-4">
                @foreach (config('global.data.campaign_run_statuses', []) as $status)
                    <div class="d-flex align-items-center gap-1">
                        <span class="w-10px h-10px rounded-1 bg-{{ $status['color'] }} d-inline-block"></span>
                        <span class="text-muted fs-7 fw-semibold">{{ $status['label'] }}</span>
                    </div>
                @endforeach
                {{-- Static swatch for projected recurring occurrences (virtual — not a persisted run status) --}}
                <div class="d-flex align-items-center gap-1">
                    <span class="w-10px h-10px rounded-1 bg-info d-inline-block"></span>
                    <span class="text-muted fs-7 fw-semibold">Planifiée (récurrence)</span>
                </div>
                {{-- Static swatch matching the greyed-out non-working-day columns (weekends + blackout dates) --}}
                <div class="d-flex align-items-center gap-1">
                    <span class="w-10px h-10px rounded-1 bg-gray-300 d-inline-block"></span>
                    <span class="text-muted fs-7 fw-semibold">Jour non ouvré</span>
                </div>
                @can('view prospect_criteria')
                <div class="d-flex align-items-center gap-1">
                    <span class="w-10px h-10px rounded-1 bg-warning d-inline-block"></span>
                    <span class="text-muted fs-7 fw-semibold">D&eacute;couverte automatique</span>
                </div>
                @endcan
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
                <div class="mb-7">
                    <div class="d-none mb-3" data-kt-planner-row="sequenceName">
                        <span class="text-muted fw-semibold me-2">S&eacute;quence :</span>
                        <span class="fw-bold" data-kt-planner-value="sequenceName"></span>
                    </div>
                    <div class="d-none mb-3" data-kt-planner-row="waveNumber">
                        <span class="text-muted fw-semibold me-2">Vague :</span>
                        <span class="fw-bold" data-kt-planner-value="waveNumber"></span>
                    </div>
                    <div class="d-none mb-3" data-kt-planner-row="stepNumber">
                        <span class="text-muted fw-semibold me-2">&Eacute;tape :</span>
                        <span class="fw-bold" data-kt-planner-value="stepNumber"></span>
                    </div>
                    <div class="d-none" data-kt-planner-row="companyLimit">
                        <span class="text-muted fw-semibold me-2">Maximum de soci&eacute;t&eacute;s :</span>
                        <span class="fw-bold" data-kt-planner-value="companyLimit"></span>
                    </div>
                </div>
                <div class="d-flex justify-content-end">
                    <a href="#" class="btn btn-primary" data-kt-planner="event_link">Voir le d&eacute;tail</a>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    /* Scoped under .fc so it wins against FullCalendar's own cell-background
       rules (which are themselves .fc-scoped) without reaching for !important.
       Specificity is intentionally 0,2,0: it ties .fc-cell-shaded / .fc-day-disabled
       and wins on source order (this block ships after the vendor stylesheet), but
       deliberately loses to .fc-day-today (0,3,0). A weekend or blackout date that
       is also today therefore keeps the today-highlight instead of greying out —
       reviewed and accepted, not an oversight. Do not bump specificity or add
       !important. */
    .fc .fc-fretiq-blocked {
        background-color: var(--bs-gray-100);
    }

    @media (max-width: 767.98px) {
        .fc .fc-header-toolbar {
            align-items: stretch;
            flex-direction: column;
            gap: .75rem;
        }

        .fc .fc-toolbar-chunk {
            display: flex;
            justify-content: center;
        }

        .fc .fc-toolbar-title {
            font-size: 1.1rem;
            text-align: center;
        }
    }
</style>

@push('scripts')
<script>
"use strict";

(function () {
    var plannerTimezone = @json($plannerTimezone);
    var plannerSkipWeekends = @json($plannerSkipWeekends);
    var plannerBlackoutDates = @json($plannerBlackout);
    var plannerBlackoutSet = {};
    plannerBlackoutDates.forEach(function (date) {
        plannerBlackoutSet[date] = true;
    });

    // Local Y-m-d key for arg.date, read in plannerTimezone — never
    // arg.date.getDay()/toISOString(), which are browser-local and render
    // wrong for any user outside plannerTimezone. 'en-CA' formats as
    // YYYY-MM-DD, matching the Y-m-d keys plannerBlackoutSet is built from.
    function localDateKey(date) {
        return FullCalendar.formatDate(date, {
            timeZone: plannerTimezone,
            locale: 'en-CA',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit'
        });
    }

    var clockEl = document.getElementById('planner-current-time');
    var clockFormatter = new Intl.DateTimeFormat('fr-FR', {
        timeZone: plannerTimezone,
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hourCycle: 'h23'
    });

    function updateClock() {
        clockEl.textContent = clockFormatter.format(new Date()) + ' (' + plannerTimezone + ')';
    }

    updateClock();
    window.setInterval(updateClock, 1000);

    var calendarEl = document.getElementById('kt_calendar_app');
    if (!calendarEl || typeof FullCalendar === 'undefined') { return; }

    var modalEl = document.getElementById('kt_modal_view_event');
    var modal = modalEl ? new bootstrap.Modal(modalEl) : null;
    var mobileViewport = window.matchMedia('(max-width: 767.98px)');
    var mobileToolbar = { left: 'prev,next', center: 'title', right: 'today' };
    var desktopToolbar = { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay' };

    function labelNavigationButtons() {
        [
            ['.fc-prev-button', 'Période précédente'],
            ['.fc-next-button', 'Période suivante'],
            ['.fc-today-button', "Aujourd'hui"]
        ].forEach(function (entry) {
            var button = calendarEl.querySelector(entry[0]);
            if (button) {
                button.setAttribute('aria-label', entry[1]);
            }
        });
    }

    var calendar = new FullCalendar.Calendar(calendarEl, {
        headerToolbar: mobileViewport.matches ? mobileToolbar : desktopToolbar,
        initialView: mobileViewport.matches ? 'listDay' : 'timeGridWeek',
        eventShortHeight: 60,
        locale: 'fr',
        timeZone: plannerTimezone,
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
        height: mobileViewport.matches ? 'auto' : 800,
        dayCellClassNames: function (arg) {
            // arg.dow is FullCalendar-computed against the calendar's own
            // configured timeZone (plannerTimezone above) — safe to use
            // directly for the weekend check, unlike arg.date.getDay().
            var isWeekend = arg.dow === 0 || arg.dow === 6;

            if (plannerSkipWeekends && isWeekend) {
                return ['fc-fretiq-blocked'];
            }

            if (plannerBlackoutSet[localDateKey(arg.date)]) {
                return ['fc-fretiq-blocked'];
            }

            return [];
        },
        eventClick: function (info) {
            info.jsEvent.preventDefault();
            if (!modal) { return; }

            var props = info.event.extendedProps || {};

            ['sequenceName', 'waveNumber', 'stepNumber', 'companyLimit'].forEach(function (key) {
                var row = modalEl.querySelector('[data-kt-planner-row="' + key + '"]');
                var value = modalEl.querySelector('[data-kt-planner-value="' + key + '"]');
                var visible = props[key] !== null && props[key] !== undefined && props[key] !== '';

                row.classList.toggle('d-none', !visible);
                if (visible) {
                    value.textContent = props[key];
                }
            });

            modalEl.querySelector('[data-kt-planner="event_name"]').textContent = info.event.title;

            var statusEl = modalEl.querySelector('[data-kt-planner="event_status"]');
            statusEl.className = 'badge badge-light-' + (props.statusColor || 'secondary');
            statusEl.textContent = props.statusLabel || '';

            modalEl.querySelector('[data-kt-planner="event_start"]').textContent = info.event.startStr
                ? new Date(info.event.startStr).toLocaleString('fr-FR', {
                    dateStyle: 'full',
                    timeStyle: 'short',
                    timeZone: plannerTimezone
                })
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
    labelNavigationButtons();

    mobileViewport.addEventListener('change', function (event) {
        calendar.setOption('headerToolbar', event.matches ? mobileToolbar : desktopToolbar);
        calendar.setOption('height', event.matches ? 'auto' : 800);
        calendar.changeView(event.matches ? 'listDay' : 'timeGridWeek');
        labelNavigationButtons();
    });
}());
</script>
@endpush

</x-default-layout>
