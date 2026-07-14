<x-auth-layout>
    <form class="form w-100" action="{{ route('password.confirm') }}" method="POST">
        @csrf

        <div class="text-center mb-10">
            <h1 class="text-gray-900 fw-bolder mb-3">Confirmez votre mot de passe</h1>
            <div class="text-gray-500 fw-semibold fs-6">Cette zone est sécurisée. Confirmez votre mot de passe pour continuer.</div>
        </div>

        <div class="fv-row mb-8 fv-plugins-icon-container">
            <input placeholder="Mot de passe" type="password" name="password" autocomplete="current-password" class="form-control bg-transparent @error('password') is-invalid @enderror" required>
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="d-flex flex-wrap justify-content-center pb-lg-0">
            <button type="submit" class="btn btn-primary me-4">@include('partials/general/_button-indicator', ['label' => 'Confirmer'])</button>
            <a href="{{ route('dashboard') }}" class="btn btn-light">Annuler</a>
        </div>
    </form>
</x-auth-layout>
