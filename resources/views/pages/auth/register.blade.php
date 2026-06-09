<x-auth-layout>

    <!--begin::Form-->
    <form class="form w-100" novalidate="novalidate" id="kt_sign_up_form" data-kt-redirect-url="{{ route('login') }}" action="{{ route('register') }}" method="POST">
        @csrf

        <!--begin::Heading-->
        <div class="text-center mb-11">
            <!--begin::Title-->
            <h1 class="text-gray-900 fw-bolder mb-3">
                Créer un compte
            </h1>
            <!--end::Title-->

            <!--begin::Subtitle-->
            <div class="text-gray-500 fw-semibold fs-6">
                Plateforme de prospection fret
            </div>
            <!--end::Subtitle-->
        </div>
        <!--end::Heading-->

        <!--begin::Input group — Nom-->
        <div class="fv-row mb-8">
            <input
                type="text"
                placeholder="Nom complet"
                name="name"
                autocomplete="name"
                class="form-control bg-transparent @error('name') is-invalid @enderror"
                value="{{ old('name') }}"
            />
            @error('name')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
        <!--end::Input group-->

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

        <!--begin::Input group — Mot de passe-->
        <div class="fv-row mb-8" data-kt-password-meter="true">
            <!--begin::Wrapper-->
            <div class="mb-1">
                <!--begin::Input wrapper-->
                <div class="position-relative mb-3">
                    <input
                        class="form-control bg-transparent @error('password') is-invalid @enderror"
                        type="password"
                        placeholder="Mot de passe"
                        name="password"
                        autocomplete="new-password"
                    />
                    <span class="btn btn-sm btn-icon position-absolute translate-middle top-50 end-0 me-n2" data-kt-password-meter-control="visibility">
                        <i class="bi bi-eye-slash fs-2"></i>
                        <i class="bi bi-eye fs-2 d-none"></i>
                    </span>
                </div>
                <!--end::Input wrapper-->

                <!--begin::Meter-->
                <div class="d-flex align-items-center mb-3" data-kt-password-meter-control="highlight">
                    <div class="flex-grow-1 bg-secondary bg-active-success rounded h-5px me-2"></div>
                    <div class="flex-grow-1 bg-secondary bg-active-success rounded h-5px me-2"></div>
                    <div class="flex-grow-1 bg-secondary bg-active-success rounded h-5px me-2"></div>
                    <div class="flex-grow-1 bg-secondary bg-active-success rounded h-5px"></div>
                </div>
                <!--end::Meter-->
            </div>
            <!--end::Wrapper-->

            @error('password')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror

            <!--begin::Hint-->
            <div class="text-muted">
                Minimum 8 caractères, avec lettres, chiffres et symboles.
            </div>
            <!--end::Hint-->
        </div>
        <!--end::Input group-->

        <!--begin::Input group — Confirmation mot de passe-->
        <div class="fv-row mb-8">
            <input
                placeholder="Confirmer le mot de passe"
                name="password_confirmation"
                type="password"
                autocomplete="new-password"
                class="form-control bg-transparent"
            />
        </div>
        <!--end::Input group-->

        <!--begin::Submit button-->
        <div class="d-grid mb-10">
            <button type="submit" id="kt_sign_up_submit" class="btn btn-primary">
                @include('partials/general/_button-indicator', ['label' => "S'inscrire"])
            </button>
        </div>
        <!--end::Submit button-->

        <!--begin::Sign in link-->
        <div class="text-gray-500 text-center fw-semibold fs-6">
            Vous avez déjà un compte ?
            <a href="{{ route('login') }}" class="link-primary fw-semibold">
                Se connecter
            </a>
        </div>
        <!--end::Sign in link-->
    </form>
    <!--end::Form-->

</x-auth-layout>
