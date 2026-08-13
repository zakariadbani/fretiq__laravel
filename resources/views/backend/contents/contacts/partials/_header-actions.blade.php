{{--
    Contact hero action buttons shared by view and edit.
    Used by _header-with-tabs.blade.php shim.

    Variables: $model, $isView (bool)
--}}

@if($isView)
    @can('verify contacts')
        @php
            $verificationStatus = strtolower((string) $model->email_verification_status);
            $hasVerificationEvidence = filled($model->email_verification_status)
                || filled($model->email_verification_source)
                || $model->email_verification_checked_at !== null;
            $verificationEnabled = app(\App\Services\Discovery\EmailVerificationSettings::class)->enabled();
        @endphp
        @if($verificationStatus === 'pending')
            <span class="badge badge-light-primary">Vérification en cours</span>
        @else
            <form method="POST" action="{{ route('admin.contacts.verify-email', $model->id) }}" class="d-inline">
                @csrf
                <label class="form-check form-check-inline form-check-sm mb-0 me-1" title="Confirme le coût avant l’appel au service externe">
                    <input class="form-check-input" type="checkbox" name="confirm_provider_cost" value="1" required>
                    <span class="form-check-label">Confirmer</span>
                </label>
                @if($hasVerificationEvidence)
                    <input type="hidden" name="force" value="1">
                    <input type="hidden" name="client_token" value="{{ \Illuminate\Support\Str::uuid() }}">
                @endif
                <button type="submit" class="btn btn-sm btn-light-primary" {{ $verificationEnabled ? '' : 'disabled' }}>
                    {{ $hasVerificationEvidence ? 'Revérifier' : 'Vérifier' }} l’email
                    ({{ number_format((float) config('prospecting.provider_units.hunter.email_verifier', 0.5), 1, ',', ' ') }} crédit)
                </button>
            </form>
            @if(! $verificationEnabled)
                <span class="text-muted fs-8">Désactivée dans les paramètres</span>
            @endif
        @endif
    @endcan
    @can('edit contacts')
        <a href="{{ route('admin.contacts.edit', $model->id) }}" class="btn btn-sm btn-primary">
            <i class="bi bi-pencil me-1"></i>
            Modifier
        </a>
    @endcan
@endif

@can('create demandes')
    @if(Route::has('admin.demandes.create'))
        <a href="{{ route('admin.demandes.create', ['contact_id' => $model->id]) }}" class="btn btn-sm btn-light btn-active-light-success">
            <i class="bi bi-plus-circle me-1"></i>
            Creer une demande
        </a>
    @endif
@endcan
