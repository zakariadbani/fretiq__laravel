<x-auth-layout>

    <!--begin::Form-->
    <form class="form w-100" novalidate="novalidate" id="kt_sign_in_form" data-kt-redirect-url="{{ route('admin.dashboard') }}" action="{{ route('login') }}" method="POST">
        @csrf

        <!--begin::Heading-->
        <div class="text-center mb-11">
            <!--begin::Title-->
            <h1 class="text-gray-900 fw-bolder mb-3">
                Connexion
            </h1>
            <!--end::Title-->

            <!--begin::Subtitle-->
            <div class="text-gray-500 fw-semibold fs-6">
                Plateforme de prospection fret
            </div>
            <!--end::Subtitle-->
        </div>
        <!--end::Heading-->

        <!--begin::Separator-->
        <div class="separator separator-content my-10">
            <span class="w-175px text-gray-500 fw-semibold fs-7">Entrez vos identifiants</span>
        </div>
        <!--end::Separator-->

        <!--begin::Input group — Email-->
        <div class="fv-row mb-8">
            <input
                type="email"
                placeholder="E-mail"
                name="email"
                autocomplete="email"
                class="form-control bg-transparent @error('email') is-invalid @enderror"
                value="{{ old('email') }}"
            />
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
        <!--end::Input group-->

        <!--begin::Input group — Password-->
        <div class="fv-row mb-3">
            <input
                type="password"
                placeholder="Mot de passe"
                name="password"
                autocomplete="current-password"
                class="form-control bg-transparent @error('password') is-invalid @enderror"
            />
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
        <!--end::Input group-->

        <!--begin::Wrapper-->
        <div class="d-flex flex-stack flex-wrap gap-3 fs-base fw-semibold mb-8">
            <div></div>

            <!--begin::Forgot password link-->
            <a href="{{ route('password.request') }}" class="link-primary">
                Mot de passe oublié ?
            </a>
            <!--end::Forgot password link-->
        </div>
        <!--end::Wrapper-->

        <!--begin::Submit button-->
        <div class="d-grid mb-10">
            <button type="submit" id="kt_sign_in_submit" class="btn btn-primary">
                @include('partials/general/_button-indicator', ['label' => 'Se connecter'])
            </button>
        </div>
        <!--end::Submit button-->

        <!--begin::Contact admin-->
        <div class="text-gray-500 text-center fw-semibold fs-6">
            Pas encore de compte ?
            <a href="mailto:admin@fretiq.com" class="link-primary">
                Contacter l'administrateur
            </a>
        </div>
        <!--end::Contact admin-->
    </form>
    <!--end::Form-->

</x-auth-layout>
