<!--begin::Navbar-->
<div class="app-navbar flex-shrink-0 ms-auto">
	<!--begin::Quick actions-->
	@include(config('settings.KT_THEME_LAYOUT_DIR').'/partials/sidebar-layout/header/_quick-actions')
	<!--end::Quick actions-->
	<!--begin::User menu-->
	@if(Auth::user())
	<div class="app-navbar-item ms-1 ms-md-4" id="kt_header_user_menu_toggle">
		<!--begin::Menu wrapper-->
		<button type="button" class="btn p-0 border-0 bg-transparent symbol symbol-35px" data-kt-menu-trigger="{default: 'click', lg: 'hover'}" data-kt-menu-attach="parent" data-kt-menu-placement="bottom-end" aria-label="Ouvrir le menu du compte">
			@if(Auth::user()->profile_photo_url)
			<img src="{{ \Auth::user()->profile_photo_url }}" class="rounded-3" alt="user" />
			@else
			<div class="symbol-label fs-3 {{ app(\App\Actions\GetThemeType::class)->handle('bg-light-? text-?', Auth::user()->name ?? 'User') }}">
				{{ substr(Auth::user()->name ?? 'User', 0, 1) }}
			</div>
			@endif
		</button>
		@include('partials/menus/_user-account-menu')
		<!--end::Menu wrapper-->
	</div>
	@endif
	<!--end::User menu-->
</div>
<!--end::Navbar-->
