<x-auth-layout>

    <div class="form w-100">
        <!--begin::Heading-->
        <div class="text-center mb-11">
            <!--begin::Title-->
            <h1 class="text-gray-900 fw-bolder mb-3">
                Inscription désactivée
            </h1>
            <!--end::Title-->

            <!--begin::Subtitle-->
            <div class="text-gray-500 fw-semibold fs-6">
                Les comptes Fretiq sont créés par l’administrateur TCL.
            </div>
            <!--end::Subtitle-->
        </div>
        <!--end::Heading-->

        <div class="text-gray-600 text-center fw-semibold fs-6 mb-10">
            Si vous avez besoin d’un accès, contactez l’équipe d’administration.
        </div>

        <div class="d-grid mb-10">
            <a href="mailto:admin@fretiq.com" class="btn btn-primary">Contacter l’administrateur</a>
        </div>

        <div class="text-gray-500 text-center fw-semibold fs-6">
            Vous avez déjà un compte ?
            <a href="{{ route('login') }}" class="link-primary fw-semibold">
                Se connecter
            </a>
        </div>
    </div>

</x-auth-layout>
