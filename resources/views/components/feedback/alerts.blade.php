@php
    $alerts = [
        ['key' => 'success', 'class' => 'success', 'icon' => 'check-circle', 'role' => 'status', 'live' => 'polite'],
        ['key' => 'info', 'class' => 'info', 'icon' => 'info-circle', 'role' => 'status', 'live' => 'polite'],
        ['key' => 'warning', 'class' => 'warning', 'icon' => 'exclamation-triangle', 'role' => 'alert', 'live' => 'assertive'],
        ['key' => 'error', 'class' => 'danger', 'icon' => 'exclamation-circle', 'role' => 'alert', 'live' => 'assertive'],
    ];
@endphp

@foreach ($alerts as $alert)
    @if (session($alert['key']))
        <div class="alert alert-{{ $alert['class'] }} d-flex align-items-center alert-dismissible fade show mb-5" role="{{ $alert['role'] }}" aria-live="{{ $alert['live'] }}">
            <i class="bi bi-{{ $alert['icon'] }} fs-3 me-3" aria-hidden="true"></i>
            <div>{{ session($alert['key']) }}</div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
        </div>
    @endif
@endforeach

@if (session('status') && session('status') !== 'verification-link-sent')
    <div class="alert alert-success d-flex align-items-center alert-dismissible fade show mb-5" role="status" aria-live="polite">
        <i class="bi bi-check-circle fs-3 me-3" aria-hidden="true"></i>
        <div>{{ session('status') }}</div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
    </div>
@endif

@if ($errors->any())
    <div class="alert alert-danger alert-dismissible fade show mb-5" role="alert" aria-live="assertive" tabindex="-1">
        <div class="fw-semibold mb-2">Veuillez corriger les champs signalés.</div>
        <ul class="mb-0 ps-4">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
    </div>
@endif
