@extends('layout.master')

@section('content')

    <a class="visually-hidden-focusable position-fixed top-0 start-0 m-3 btn btn-primary z-index-3" href="#main-content">
        Aller au contenu principal
    </a>

    <!--begin::App-->
    <div class="d-flex flex-column flex-root app-root" id="kt_app_root">
        <!--begin::Page-->
        <div class="app-page flex-column flex-column-fluid" id="kt_app_page">
            @include(config('settings.KT_THEME_LAYOUT_DIR').'/partials/sidebar-layout/_header')
            <!--begin::Wrapper-->
            <div class="app-wrapper flex-column flex-row-fluid" id="kt_app_wrapper">
                @include(config('settings.KT_THEME_LAYOUT_DIR').'/partials/sidebar-layout/_sidebar')
                <!--begin::Main-->
                <div class="app-main flex-column flex-row-fluid" id="kt_app_main">
                    <!--begin::Content wrapper-->
                    <div class="d-flex flex-column flex-column-fluid">
                        @include(config('settings.KT_THEME_LAYOUT_DIR').'/partials/sidebar-layout/_toolbar')
                        <!--begin::Content-->
                        <main id="main-content" class="app-content flex-column-fluid" tabindex="-1">
                            <!--begin::Content container-->
                            <div id="kt_app_content_container" class="app-container container-fluid">
                                @include('components.feedback.alerts')
                                {{ $slot }}
                            </div>
                            <!--end::Content container-->
                        </main>
                        <!--end::Content-->
                    </div>
                    <!--end::Content wrapper-->
                    @include(config('settings.KT_THEME_LAYOUT_DIR').'/partials/sidebar-layout/_footer')
                </div>
                <!--end:::Main-->
            </div>
            <!--end::Wrapper-->
        </div>
        <!--end::Page-->
    </div>
    <!--end::App-->

    @include('partials/_scrolltop')

@endsection
