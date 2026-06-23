<?php

namespace App\Http\Controllers\Backend;

use App\Crud\ViewConfigs\PackageViewConfig;
use App\DataTables\Backend\PackagesDataTable;
use App\Http\Controllers\Traits\Crudable;
use App\Http\Controllers\Traits\Datatableable;
use App\Models\Package;
use App\Models\PackageAssignment;
use App\Models\DiscoveryRun;
use App\Services\Quota\DiscoveryQuotaService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PackageController extends BackendController
{
    use Crudable, Datatableable;

    /**
     * Fields the generic executeSwitch() AJAX toggle is allowed to update.
     */
    protected array $toggleableFields = ['is_active'];

    public function __construct(Request $request, Package $model, PackagesDataTable $dataTable)
    {
        parent::__construct($request, $model, $dataTable);

        // Assigned at runtime to avoid a trait+class property default conflict.
        $this->viewConfigClass = PackageViewConfig::class;

        // ONE permission gates all four blocks — superadmin only.
        $this->middleware('permission:manage packages')->only(['index', 'view']);
        $this->middleware('permission:manage packages')->only(['create', 'store']);
        $this->middleware('permission:manage packages')->only(['edit', 'update', 'executeSwitch']);
        $this->middleware('permission:manage packages')->only(['delete']);
        $this->middleware('permission:manage packages')->only(['assign']);

        $this->listTitle = 'Packs';
        $this->title     = 'name';

        $this->bootResource(new BackendResource(
            modelClass:       Package::class,
            modelName:        'packages',
            dataTableClass:   PackagesDataTable::class,
            permissionEntity: 'packages',
            prefixName:       'admin',
            titleField:       'name',
        ));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Index override — injects dataTableConfig + dashboard cards data
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Override the trait's index() to pass the dynamic filter config for JS
     * and inject the "Pack actif" + consumption ledger card data.
     */
    public function index()
    {
        $quotaService = app(DiscoveryQuotaService::class);

        // Active package assignment
        $activeAssignment = PackageAssignment::with(['package', 'assignedBy'])->orderByDesc('id')->first();

        // Packages list for the assign form (active only, ordered by sort_order)
        $activePackages = Package::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();

        // Consumption ledger: last 14 days of discovery_runs grouped by quota_date
        $since = Carbon::today()->subDays(13);
        $ledger = DiscoveryRun::where('quota_date', '>=', $since->toDateString())
            ->selectRaw('quota_date, COUNT(*) as runs_count, SUM(credits_reserved) as total_reserved, SUM(consumed) as total_consumed, SUM(contact_consumed) as total_contact_consumed')
            ->groupBy('quota_date')
            ->orderByDesc('quota_date')
            ->get();

        // Today's remaining (null = unlimited)
        $remainingToday               = $quotaService->remainingTodayForDisplay();
        $isUnlimited                  = $quotaService->isUnlimited();
        $contactRemainingToday        = $quotaService->contactRemainingTodayForDisplay();

        // Monthly remaining (null = no monthly cap)
        $monthlyRemainingToday        = $quotaService->monthlyRemainingForDisplay();
        $monthlyContactRemainingToday = $quotaService->monthlyContactRemainingForDisplay();

        return $this->currentDataTable->render(
            'backend.contents.packages.crud.index',
            [
                'listTitle'                    => $this->listTitle,
                'dataTableConfig'              => $this->currentDataTable->getIndexConfig(),
                'activeAssignment'             => $activeAssignment,
                'activePackages'               => $activePackages,
                'ledger'                       => $ledger,
                'remainingToday'               => $remainingToday,
                'isUnlimited'                  => $isUnlimited,
                'contactRemainingToday'        => $contactRemainingToday,
                'monthlyRemainingToday'        => $monthlyRemainingToday,
                'monthlyContactRemainingToday' => $monthlyContactRemainingToday,
            ]
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // View override — inject stats
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Override view() to inject $stats for ViewConfig apercu.
     */
    public function view($id)
    {
        $model = $this->currentModel->withCount('assignments')->find($id);

        if ($model === null) {
            session()->flash('error', trans('app.not_found'));
            return redirect(route('admin.packages.index'));
        }

        return $this->getView('backend.contents.packages.crud.view')
            ->with('title', __('overview'))
            ->with('model', $model)
            ->with('stats', $this->packageStats($model));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Assign action — "Pack actif" switcher
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /admin/packages/assign
     * Creates a new PackageAssignment row, making the selected package active.
     */
    public function assign(Request $request)
    {
        $validated = $request->validate([
            'package_id' => 'required|integer|exists:packages,id',
        ]);

        PackageAssignment::create([
            'package_id'  => $validated['package_id'],
            'assigned_by' => auth()->id(),
        ]);

        session()->flash('success', 'Pack actif mis à jour avec succès.');

        return redirect()->back();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // View vars — no dropdowns needed for the create/edit form
    // ─────────────────────────────────────────────────────────────────────────

    protected function getViewVars(): array
    {
        return [];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Stats — for ViewConfig apercu
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build analytics payload for a Package.
     * assignments_count is eager-loaded via withCount().
     */
    private function packageStats(Package $package): array
    {
        // Count of discovery runs authorised under any of this package's assignments
        $assignmentIds = $package->assignments()->pluck('id');
        $runsCount = $assignmentIds->isNotEmpty()
            ? DiscoveryRun::whereIn('package_assignment_id', $assignmentIds)->count()
            : 0;

        return [
            'assignments_count' => $package->assignments_count ?? 0,
            'runs_count'        => $runsCount,
        ];
    }
}
