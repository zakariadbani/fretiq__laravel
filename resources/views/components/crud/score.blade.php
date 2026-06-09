{{--
    Generic anonymous Blade component: <x-crud.score :value="$model->ai_score" />

    Renders a numeric score + Bootstrap progress bar.
    Thresholds: keys are Bootstrap color names, values are minimum scores (descending).
    Default thresholds reproduce x-companies.score exactly (≥70 success, ≥40 warning, else danger).
    null value → muted dash —.

    Props:
        value       (int|null)  — the score to display
        max         (int)       — denominator for bar width; default 100
        thresholds  (array)     — [ 'success'=>70, 'warning'=>40, 'danger'=>0 ] descending
        showBar     (bool)      — whether to render the progress bar; default true
--}}
@props([
    'value'      => null,
    'max'        => 100,
    'thresholds' => ['success' => 70, 'warning' => 40, 'danger' => 0],
    'showBar'    => true,
])

@if($value === null)
    <span class="text-muted">—</span>
@else
    @php
        $v = (int) $value;

        // Pick first color whose min-value <= $v (thresholds must be descending by min-value).
        $chosenColor = 'secondary';
        arsort($thresholds); // ensure descending order by value
        foreach ($thresholds as $color => $min) {
            if ($v >= $min) {
                $chosenColor = $color;
                break;
            }
        }

        $textClass  = 'text-' . $chosenColor;
        $barClass   = 'bg-' . $chosenColor;
        $trackClass = 'bg-light-' . $chosenColor;

        $barWidth = $max > 0 ? min(100, round($v / $max * 100)) : 0;
    @endphp
    <div class="d-flex align-items-center">
        <span class="fw-bold me-2 {{ $textClass }} fs-6">{{ $v }}</span>
        @if($showBar)
            <div class="progress h-8px w-75px {{ $trackClass }}">
                <div class="progress-bar {{ $barClass }}"
                     role="progressbar"
                     style="width: {{ $barWidth }}%"
                     aria-valuenow="{{ $v }}"
                     aria-valuemin="0"
                     aria-valuemax="{{ $max }}"></div>
            </div>
        @endif
    </div>
@endif
