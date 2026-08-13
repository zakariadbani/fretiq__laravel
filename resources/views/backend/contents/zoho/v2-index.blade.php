<x-default-layout>

@section('title', 'Synchronisation Zoho')
@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Administration'], ['label' => 'Zoho']]" />
@endsection

@php
    $actionModules = collect($dashboard['modules'])->filter(
        fn (array $module, string $key) => $module['active'] && $key !== 'quoted_items'
    );
    $syncAllBatch = $dashboard['syncAllBatch'] ?? null;
    $syncAllState = $dashboard['syncAllState'] ?? 'idle';
    $syncAllActive = $syncAllState === 'active';
    $syncAllPaused = $syncAllState === 'paused';
@endphp

    @can('sync zoho')
        <section class="mb-8" data-zoho-sync-center aria-labelledby="zoho-sync-center-title">
            <div class="card border border-primary border-dashed bg-light-primary mb-5">
                <div class="card-body d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-5 py-6">
                    <div class="d-flex align-items-start gap-4">
                        <span class="symbol symbol-50px flex-shrink-0">
                            <span class="symbol-label bg-primary text-white"><i class="bi bi-cloud-arrow-down-fill fs-2 text-white"></i></span>
                        </span>
                        <div>
                            <h2 class="fs-2 fw-bold text-gray-900 mb-1" id="zoho-sync-center-title">Centre de synchronisation</h2>
                            <p class="text-gray-700 mb-0">Lancez une mise à jour delta de tous les modules actifs ou choisissez un module ci-dessous.</p>
                        </div>
                    </div>
                    <div class="d-grid gap-2 flex-shrink-0">
                        <form method="POST" action="{{ route('admin.zoho.v2.sync') }}" data-zoho-sync-all-form>
                            @csrf
                            <button
                                type="submit"
                                class="btn btn-lg {{ $syncAllActive ? 'btn-light-primary' : 'btn-primary' }} w-100 text-nowrap"
                                data-zoho-sync-all-button
                                data-sync-state="{{ $syncAllState }}"
                                aria-label="{{ $syncAllActive ? 'Synchronisation complète Zoho en cours' : ($syncAllPaused ? 'Reprendre la synchronisation complète Zoho' : 'Synchroniser tous les modules Zoho actifs') }}"
                                aria-disabled="{{ $syncAllActive ? 'true' : 'false' }}"
                                @disabled($syncAllActive)
                            >
                                @if($syncAllActive)
                                    <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>En cours…
                                @elseif($syncAllPaused)
                                    <i class="bi bi-play-fill fs-3" aria-hidden="true"></i> Reprendre la synchronisation
                                @else
                                    <i class="bi bi-arrow-repeat fs-3" aria-hidden="true"></i> Synchroniser tout
                                @endif
                            </button>
                        </form>
                        @if($syncAllActive && ($dashboard['syncAllCanPause'] ?? false) && $syncAllBatch)
                            <form method="POST" action="{{ route('admin.zoho.v2.pause') }}" data-zoho-pause-all-form>
                                @csrf
                                <input type="hidden" name="batch_id" value="{{ $syncAllBatch->id }}">
                                <button type="submit" class="btn btn-light-warning w-100">
                                    <i class="bi bi-pause-fill" aria-hidden="true"></i> Mettre en pause
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>

            <div class="d-flex flex-column flex-sm-row align-items-sm-end justify-content-between gap-2 mb-4">
                <div>
                    <h3 class="fs-3 fw-bold text-gray-900 mb-1">Synchronisation par module</h3>
                    <span class="text-muted fs-7">Chaque action ajoute une synchronisation delta à la file Zoho.</span>
                </div>
                <span class="badge badge-light-primary align-self-start align-self-sm-center">{{ $actionModules->count() }} modules disponibles</span>
            </div>

            <div class="row g-4" data-zoho-module-grid>
                @foreach($actionModules as $key => $module)
                    @php
                        $checkpointStatus = (string) ($module['checkpoint']?->status ?? 'idle');
                        $syncState = (string) ($module['sync_state'] ?? 'idle');
                        $isBusy = (bool) ($module['busy'] ?? false);
                        $isPausedOwner = (bool) ($module['paused_owner'] ?? false);
                        $isInterrupted = $syncState === 'interrupted';
                        $isUnavailable = $isBusy || $isPausedOwner;
                        $buttonSyncState = $isPausedOwner ? 'paused' : ($isBusy ? 'busy' : ($isInterrupted ? 'interrupted' : 'ready'));
                        $counts = $dashboard['counts'][$key];
                    @endphp
                    <div class="col-12 col-md-6 col-xl-4 col-xxl-3">
                        <article class="card h-100 border border-gray-300" data-zoho-module-card="{{ $key }}">
                            <div class="card-body d-flex flex-column p-5">
                                <div class="d-flex align-items-start justify-content-between gap-3 mb-4">
                                    <div>
                                        <h4 class="fs-4 fw-bold text-gray-900 mb-1" data-zoho-module-label="{{ $key }}">{{ $module['label'] }}</h4>
                                        <span class="badge badge-light-{{ $module['freshness']['color'] }}">{{ $module['freshness']['label'] }}</span>
                                    </div>
                                    @if($isPausedOwner)
                                        <span class="badge badge-light-warning text-nowrap" data-zoho-module-state="{{ $key }}">
                                            <i class="bi bi-pause-fill" aria-hidden="true"></i> Lot en pause
                                        </span>
                                    @elseif($isBusy)
                                        <span class="badge badge-light-primary text-nowrap" data-zoho-module-state="{{ $key }}">
                                            <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> En cours
                                        </span>
                                    @elseif($isInterrupted)
                                        <span class="badge badge-light-danger text-nowrap" data-zoho-module-state="{{ $key }}">
                                            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Interrompue
                                        </span>
                                    @else
                                        <span class="badge badge-light-secondary text-nowrap" data-zoho-module-state="{{ $key }}">{{ $checkpointStatus === 'idle' ? 'Disponible' : ucfirst($checkpointStatus) }}</span>
                                    @endif
                                </div>

                                <div class="d-flex flex-column gap-2 fs-7 text-gray-700 mb-5">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-check-circle text-success" aria-hidden="true"></i>
                                        <span data-zoho-module-last-success="{{ $key }}">Dernier succès : <strong>{{ $module['success']?->synced_at?->format('d/m/Y H:i') ?? 'jamais' }}</strong></span>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-database text-primary" aria-hidden="true"></i>
                                        <span data-zoho-module-counts="{{ $key }}">Stock local : <strong>{{ number_format($counts['active']) }}</strong> actifs · {{ number_format($counts['tombstoned']) }} supprimés conservés</span>
                                    </div>
                                </div>

                                <form method="POST" action="{{ route('admin.zoho.v2.sync') }}" class="mt-auto d-grid" data-zoho-sync-module="{{ $key }}">
                                    @csrf
                                    <input type="hidden" name="module" value="{{ $key }}">
                                    <button
                                        type="submit"
                                        class="btn {{ $isUnavailable ? 'btn-light-primary' : 'btn-primary' }}"
                                        data-zoho-sync-button="{{ $key }}"
                                        data-sync-state="{{ $buttonSyncState }}"
                                        aria-label="{{ $isPausedOwner ? 'Module '.$module['label'].' détenu par le lot complet en pause' : ($isBusy ? 'Synchronisation du module '.$module['label'].' en cours' : ($isInterrupted ? 'Relancer la synchronisation interrompue du module '.$module['label'] : 'Synchroniser le module '.$module['label'])) }}"
                                        aria-disabled="{{ $isUnavailable ? 'true' : 'false' }}"
                                        @disabled($isUnavailable)
                                    >
                                        @if($isPausedOwner)
                                            <i class="bi bi-pause-fill" aria-hidden="true"></i> Lot complet en pause
                                        @elseif($isBusy)
                                            <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>En cours…
                                        @else
                                            <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Synchroniser
                                        @endif
                                    </button>
                                </form>
                            </div>
                        </article>
                    </div>
                @endforeach
            </div>
        </section>
    @endcan

    @if($dashboard['syncProgress'] ?? null)
        @include('backend.contents.zoho.partials._sync-progress', ['progress' => $dashboard['syncProgress']])
    @endif

    @canany(['sync zoho', 'backfill zoho'])
        <section class="card mb-6" data-zoho-maintenance-controls aria-labelledby="zoho-maintenance-title">
            <div class="card-header align-items-center">
                <div>
                    <h3 class="card-title mb-0" id="zoho-maintenance-title">Maintenance avancée</h3>
                    <span class="text-muted fs-8">Backfill, réconciliation et reprise des anomalies.</span>
                </div>
                <span class="badge badge-light-secondary">Actions secondaires</span>
            </div>
            <div class="card-body">
                <div class="row g-4 align-items-end">
                    @can('sync zoho')
                        <div class="col-12 col-lg-4">
                            <form method="POST" action="{{ route('admin.zoho.v2.retry') }}" class="d-grid" data-zoho-maintenance="retry-all">
                                @csrf
                                <button type="submit" class="btn btn-light-warning">
                                    <i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Réessayer toutes les anomalies
                                </button>
                            </form>
                        </div>
                    @endcan
                    @can('backfill zoho')
                        <div class="col-12 col-lg-4">
                            <form method="POST" action="{{ route('admin.zoho.v2.backfill') }}" data-zoho-maintenance="backfill">
                                @csrf
                                <input type="hidden" name="mode" value="backfill">
                                <label class="form-label fs-7" for="zoho-backfill-module">Module à reprendre intégralement</label>
                                <div class="d-grid gap-2">
                                    <select class="form-select" id="zoho-backfill-module" name="module">
                                        <option value="">Tous les modules actifs</option>
                                        @foreach($actionModules as $key => $module)<option value="{{ $key }}">{{ $module['label'] }}</option>@endforeach
                                    </select>
                                    <button type="submit" class="btn btn-light-primary">Lancer le backfill</button>
                                </div>
                            </form>
                        </div>
                        <div class="col-12 col-lg-4">
                            <form method="POST" action="{{ route('admin.zoho.v2.backfill') }}" data-zoho-maintenance="reconcile">
                                @csrf
                                <input type="hidden" name="mode" value="reconcile">
                                <label class="form-label fs-7" for="zoho-reconcile-module">Module à comparer avec Zoho</label>
                                <div class="d-grid gap-2">
                                    <select class="form-select" id="zoho-reconcile-module" name="module">
                                        <option value="">Tous les modules actifs</option>
                                        @foreach($actionModules as $key => $module)<option value="{{ $key }}">{{ $module['label'] }}</option>@endforeach
                                    </select>
                                    <button type="submit" class="btn btn-light-info">Lancer la réconciliation</button>
                                </div>
                            </form>
                        </div>
                    @endcan
                </div>
            </div>
        </section>
@endcanany

@if($dashboard['syncProgress'] ?? null)
    @push('scripts')
        @include('backend.contents.zoho.partials._sync-progress-script')
    @endpush
@endif
</x-default-layout>
