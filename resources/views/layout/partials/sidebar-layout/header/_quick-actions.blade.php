<!--begin::Quick actions-->
@canany(['create companies', 'create contacts', 'create campaigns', 'create segments', 'create demandes', 'create sequences', 'create prospect_criteria'])
<div class="app-navbar-item ms-1 ms-md-3">
	<button type="button"
		 class="btn btn-icon btn-custom btn-icon-muted btn-active-light btn-active-color-primary w-35px h-35px"
		 data-kt-menu-trigger="{default: 'click', lg: 'hover'}"
		 data-kt-menu-attach="parent"
		 data-kt-menu-placement="bottom-start"
		 aria-label="Ouvrir les actions rapides"
		 title="Actions rapides">
		{!! getIcon('flash-circle', 'fs-1') !!}
	</button>
	<!--begin::Quick actions menu-->
	<div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-800 menu-state-bg menu-state-color fw-semibold py-4 fs-6 w-275px" data-kt-menu="true">
		<div class="menu-item px-3">
			<div class="menu-content d-flex align-items-center px-3">
				<div class="d-flex flex-column">
					<div class="fw-bold fs-5">Actions rapides</div>
					<span class="text-muted fs-7">Créer un nouvel élément</span>
				</div>
			</div>
		</div>
		<div class="separator my-2"></div>
		@can('create companies')
		<div class="menu-item px-5">
			<a href="{{ route('admin.companies.create') }}" class="menu-link px-5">
				<span class="menu-icon">{!! getIcon('home-2', 'fs-4 text-primary') !!}</span>
				<span class="menu-title">Nouvelle entreprise</span>
			</a>
		</div>
		@endcan
		@can('create contacts')
		<div class="menu-item px-5">
			<a href="{{ route('admin.contacts.create') }}" class="menu-link px-5">
				<span class="menu-icon">{!! getIcon('profile-user', 'fs-4 text-success') !!}</span>
				<span class="menu-title">Nouveau contact</span>
			</a>
		</div>
		@endcan
		@can('create campaigns')
		<div class="menu-item px-5">
			<a href="{{ route('admin.campaigns.create') }}" class="menu-link px-5">
				<span class="menu-icon">{!! getIcon('send', 'fs-4 text-info') !!}</span>
				<span class="menu-title">Nouvelle campagne</span>
			</a>
		</div>
		@endcan
		@can('create segments')
		<div class="menu-item px-5">
			<a href="{{ route('admin.segments.create') }}" class="menu-link px-5">
				<span class="menu-icon">{!! getIcon('filter', 'fs-4 text-warning') !!}</span>
				<span class="menu-title">Nouveau segment</span>
			</a>
		</div>
		@endcan
		@can('create demandes')
		<div class="menu-item px-5">
			<a href="{{ route('admin.demandes.create') }}" class="menu-link px-5">
				<span class="menu-icon">{!! getIcon('document', 'fs-4 text-primary') !!}</span>
				<span class="menu-title">Nouvelle demande</span>
			</a>
		</div>
		@endcan
		@can('create sequences')
		<div class="menu-item px-5">
			<a href="{{ route('admin.sequences.create') }}" class="menu-link px-5">
				<span class="menu-icon">{!! getIcon('abstract-26', 'fs-4 text-success') !!}</span>
				<span class="menu-title">Nouvelle séquence</span>
			</a>
		</div>
		@endcan
		@can('create prospect_criteria')
		<div class="separator my-2"></div>
		<div class="menu-item px-5">
			<a href="{{ route('admin.prospect_criteria.create') }}" class="menu-link px-5">
				<span class="menu-icon">{!! getIcon('magnifier', 'fs-4 text-dark') !!}</span>
				<span class="menu-title">Critère de découverte</span>
			</a>
		</div>
		@endcan
	</div>
	<!--end::Quick actions menu-->
</div>
@endcanany
<!--end::Quick actions-->
