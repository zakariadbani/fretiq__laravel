<x-auth-layout>
    <!--begin::Email Form-->
    <div class="w-100">

        <div class="text-center mb-11">
            <!--begin::Title-->
            <h1 class="text-gray-900 fw-bolder mb-3">{{ __('auth.verify_email.title') }}</h1>
            <!--end::Title-->
            <!--begin::Subtitle-->
            <div class="text-gray-500 fw-semibold fs-6">{{ __('auth.verify_email.instructions') }}</div>
            <!--end::Subtitle=-->

            <!--begin::Session Status-->
            @if (session('status') === 'verification-link-sent')
                <p class="font-medium text-sm text-gray-500 mt-4">
                    {{ __('auth.verify_email.resent') }}
                </p>
            @endif
        <!--end::Session Status-->
        </div>

        <!--begin::Actions-->
        <div class="d-flex flex-wrap justify-content-center pb-lg-0">

            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <button type="submit" class="btn btn-lg btn-primary fw-bolder me-4">{{ __('auth.verify_email.resend') }}</button>
            </form>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn btn-lg btn-light-primary fw-bolder me-4">{{ __('auth.verify_email.logout') }}</button>
            </form>
        </div>
        <!--end::Actions-->
    </div>

    <!--end::Email Form-->
</x-auth-layout>
