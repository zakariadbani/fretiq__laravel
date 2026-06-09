{{--
    Anonymous Blade component: <x-companies.score :score="$model->ai_score" />

    Renders the AI-score number + Bootstrap progress bar.
    Visually identical to the PHP closure in CompaniesDataTable::createEditColumns().
    Keep both in sync if the design changes (progress height, threshold colours, bar width).

    Thresholds: ≥70 success, ≥40 warning, else danger. null → muted dash.
--}}
@props(['score' => null])

@if($score === null)
    <span class="text-muted">—</span>
@else
    @php
        $s = (int) $score;
        if ($s >= 70) {
            $textClass  = 'text-success';
            $barClass   = 'bg-success';
            $trackClass = 'bg-light-success';
        } elseif ($s >= 40) {
            $textClass  = 'text-warning';
            $barClass   = 'bg-warning';
            $trackClass = 'bg-light-warning';
        } else {
            $textClass  = 'text-danger';
            $barClass   = 'bg-danger';
            $trackClass = 'bg-light-danger';
        }
    @endphp
    <div class="d-flex align-items-center">
        <span class="fw-bold me-2 {{ $textClass }} fs-6">{{ $s }}</span>
        <div class="progress h-8px w-75px {{ $trackClass }}">
            <div class="progress-bar {{ $barClass }}"
                 role="progressbar"
                 style="width: {{ $s }}%"
                 aria-valuenow="{{ $s }}"
                 aria-valuemin="0"
                 aria-valuemax="100"></div>
        </div>
    </div>
@endif
