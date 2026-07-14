<x-auth-layout>
    <form class="form w-100" action="{{ route('password.email') }}" method="POST">
        @csrf

        <div class="text-center mb-10">
            <h1 class="text-gray-900 fw-bolder mb-3">Mot de passe oublié ?</h1>
            <div class="text-gray-500 fw-semibold fs-6">Saisissez votre e-mail pour réinitialiser votre mot de passe.</div>
        </div>

        <div class="fv-row mb-8">
            <input type="email" placeholder="E-mail" name="email" autocomplete="email" class="form-control bg-transparent @error('email') is-invalid @enderror" value="{{ old('email') }}" required />
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="d-flex flex-wrap justify-content-center pb-lg-0">
            <button type="submit" class="btn btn-primary me-4">@include('partials/general/_button-indicator', ['label' => 'Envoyer le lien de réinitialisation'])</button>
            <a href="{{ route('login') }}" class="btn btn-light">Annuler</a>
        </div>
    </form>
</x-auth-layout>
