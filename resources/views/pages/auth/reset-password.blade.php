<x-auth-layout>
    <form class="form w-100" action="{{ route('password.update') }}" method="POST">
        @csrf
        <input type="hidden" name="token" value="{{ $request->token }}">
        <input type="hidden" name="email" value="{{ old('email', $request->email) }}">

        <div class="text-center mb-10">
            <h1 class="text-gray-900 fw-bolder mb-3">Créer un nouveau mot de passe</h1>
            <div class="text-gray-500 fw-semibold fs-6">Choisissez un nouveau mot de passe.</div>
        </div>

        <div class="fv-row mb-8">
            <input class="form-control bg-transparent @error('password') is-invalid @enderror" type="password" placeholder="Nouveau mot de passe" name="password" autocomplete="new-password" required />
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="fv-row mb-8">
            <input placeholder="Confirmer le mot de passe" name="password_confirmation" type="password" autocomplete="new-password" class="form-control bg-transparent @error('password_confirmation') is-invalid @enderror" required />
            @error('password_confirmation')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="d-flex flex-wrap justify-content-center pb-lg-0">
            <button type="submit" class="btn btn-primary me-4">@include('partials/general/_button-indicator', ['label' => 'Réinitialiser le mot de passe'])</button>
            <a href="{{ route('login') }}" class="btn btn-light">Annuler</a>
        </div>
    </form>
</x-auth-layout>
