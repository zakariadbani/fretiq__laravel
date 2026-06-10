{{--
    Quota badge — shows daily solde for the active discovery package.

    Variables (injected by the including template):
        $quotaRemaining  ?int   — null when unlimited, int >= 0 when limited
        $quotaPackage    ?Package — the active Package model, or null (= no assignment = unlimited)

    Renders nothing when both are null (tables may not exist yet / no package assigned
    and quota service fell back to null).
--}}

@if($quotaRemaining === null)
    {{-- Unlimited or no package assigned --}}
    @php
        $packLabel = $quotaPackage?->name ?? 'Illimité';
    @endphp
    <span class="badge badge-light fs-7 fw-semibold">
        <i class="bi bi-infinity me-1"></i>
        {{ $packLabel }}
    </span>
@else
    @php
        $daily     = $quotaPackage?->daily_credits ?? 0;
        $packName  = $quotaPackage?->name ?? 'Pack';

        if ($quotaRemaining === 0) {
            $badgeClass = 'badge-light-danger';
        } elseif ($daily > 0 && ($quotaRemaining / $daily) <= 0.20) {
            $badgeClass = 'badge-light-warning';
        } else {
            $badgeClass = 'badge-light-success';
        }
    @endphp
    <span class="badge {{ $badgeClass }} fs-7 fw-semibold">
        <i class="bi bi-coin me-1"></i>
        Crédits du jour : {{ $quotaRemaining }} / {{ $daily }} &middot; Pack {{ e($packName) }}
    </span>
@endif
