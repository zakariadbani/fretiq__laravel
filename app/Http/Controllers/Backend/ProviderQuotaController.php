<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Jobs\DrainEnrichmentJob;
use App\Models\ProviderCall;
use App\Services\Discovery\CompanyDiscoveryService;
use App\Services\Discovery\EnrichmentDrainService;
use App\Services\Discovery\HunterEnrichmentService;
use App\Services\Quota\DiscoveryQuotaService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class ProviderQuotaController extends Controller
{
    /**
     * French labels for provider_calls.operation values. Hunter's operations
     * are mapped; SerpAPI rows carry the raw engine name (google_maps, bing, …),
     * which is correct to show as-is and simply falls through unmapped.
     *
     * @var array<string, string>
     */
    private const OPERATION_LABELS = [
        'discover' => 'Découverte',
        'domain_finder' => 'Recherche de domaine',
        'domain_search' => 'Recherche de contacts',
        'company_enrichment' => 'Enrichissement société',
        'email_finder' => 'Recherche d’email',
        'email_verifier' => 'Vérification d’email',
        'account_usage' => 'Utilisation du compte',
        'usage_history' => 'Historique d’utilisation',
        'account' => 'Compte',
    ];

    public function __construct()
    {
        $this->middleware(['auth', 'verified']);
        $this->middleware('permission:view provider quota');
    }

    /**
     * Display the superadmin-only "Quota fournisseurs" page — real remaining
     * quota fetched live from the SerpAPI and Hunter.io account endpoints.
     */
    public function index()
    {
        $serpapi = app(CompanyDiscoveryService::class)->accountUsage();
        $hunter = app(HunterEnrichmentService::class)->accountUsage();
        $driverLive = config('services.serpapi.driver', 'local') !== 'local';

        return view('backend.contents.provider_quota.index', [
            'serpapi'              => $serpapi,
            'hunter'               => $hunter,
            'providerReservations' => app(DiscoveryQuotaService::class)->providerReservationsToday(),
            'driverLive'           => $driverLive,
            'consumption'          => $this->consumption($serpapi, $hunter),
            // Initial button counts / disabled state. Real spend is re-snapshotted
            // server-side by the preview round-trip at click time — this is display only.
            'drainEstimate'        => $driverLive ? app(EnrichmentDrainService::class)->estimate(false) : null,
        ]);
    }

    public function refresh()
    {
        // ponytail: 60 s cooldown. Both account endpoints cost 0 quota units, but each
        // writes a ProviderCall ledger row — an unthrottled button would flood it.
        // Cache::add is the atomic test-and-set; no lock class needed.
        if (! Cache::add('provider.quota.refresh.cooldown', true, now()->addSeconds(60))) {
            return redirect()->route('admin.provider-quota.index')
                ->with('warning', "Actualisation déjà demandée il y a moins d'une minute.");
        }

        Cache::forget('provider.serpapi.account.v2');
        Cache::forget('provider.hunter.account.v2');

        return redirect()->route('admin.provider-quota.index')
            ->with('success', 'Données fournisseurs actualisées.');
    }

    /**
     * Cost preview + signed approval token for a drain. The token carries the
     * mode, include_empty flag and the company cap (a re-snapshot of eligibility
     * at click time) with a 5-minute TTL — spend is bounded server-side, never
     * from stale page-load data-attributes. Mirrors
     * ProspectCriteriaController::contactEnrichmentPreview.
     */
    public function drainPreview(Request $request)
    {
        abort_unless($request->user()->can('enrich companies'), 403);

        $validated = $request->validate([
            'mode' => 'required|in:companies,verify,full',
            'include_empty' => 'sometimes|boolean',
        ]);
        $mode = $validated['mode'];
        $includeEmpty = (bool) ($validated['include_empty'] ?? false);

        if (in_array($mode, ['verify', 'full'], true)) {
            abort_unless($request->user()->can('verify contacts'), 403);
        }

        $estimate = app(EnrichmentDrainService::class)->estimate($includeEmpty);
        // Cap = drain everything currently eligible, clamped to a sane ceiling.
        $maxCompanyAttempts = min(100000, max(0, (int) $estimate['companies_eligible']));

        // The confirm summary must reflect the clamped dispatch cap, not raw
        // eligibility: for eligible > 100k the raw figure would overstate the
        // company spend the job actually performs (the token 'max' is clamped too).
        $summaryEstimate = $estimate;
        $summaryEstimate['companies_eligible'] = $maxCompanyAttempts;
        $summaryEstimate['company_credits'] = round($maxCompanyAttempts * EnrichmentDrainService::COMPANY_UNIT_COST, 2);
        $summaryEstimate['total_credits'] = round($summaryEstimate['company_credits'] + (float) $estimate['contact_credits'], 2);

        $hunter = app(HunterEnrichmentService::class)->accountUsage();
        $hunterRemaining = isset($hunter['searches_available']) ? max(0, (int) $hunter['searches_available']) : null;
        $verificationsRemaining = isset($hunter['verifications_available']) ? max(0, (int) $hunter['verifications_available']) : null;
        $resetDate = isset($hunter['reset_date'])
            ? rescue(fn () => \Illuminate\Support\Carbon::parse($hunter['reset_date'])->format('d/m/Y'), null, false)
            : null;

        $token = Crypt::encryptString(json_encode([
            'mode' => $mode,
            'include_empty' => $includeEmpty,
            'max' => $maxCompanyAttempts,
            'ts' => time(),
        ], JSON_THROW_ON_ERROR));

        return response()->json([
            'ok' => true,
            'token' => $token,
            'summary' => $this->drainSummary($mode, $summaryEstimate),
            'companies_eligible' => (int) $estimate['companies_eligible'],
            'contacts_unverified' => (int) $estimate['contacts_unverified'],
            'total_credits' => (float) $estimate['total_credits'],
            'hunter_remaining' => $hunterRemaining,
            'verifications_remaining' => $verificationsRemaining,
            'reset_date' => $resetDate,
        ], 200)->header('Cache-Control', 'no-store');
    }

    /**
     * Validate the signed approval token and dispatch the drain. Single-flight
     * via the atomic `drain:active` marker (Cache::add, same idiom as refresh()).
     */
    public function drain(Request $request)
    {
        abort_unless($request->user()->can('enrich companies'), 403);

        $validated = $request->validate([
            'mode' => 'required|in:companies,verify,full',
        ]);
        $mode = $validated['mode'];

        if (in_array($mode, ['verify', 'full'], true)) {
            abort_unless($request->user()->can('verify contacts'), 403);
        }

        try {
            $approval = json_decode(Crypt::decryptString((string) $request->input('token')), true, 512, JSON_THROW_ON_ERROR);
            $ts = (int) ($approval['ts'] ?? 0);
            $includeEmpty = (bool) ($approval['include_empty'] ?? false);
            $max = max(0, min(100000, (int) ($approval['max'] ?? 0)));
            $valid = (string) ($approval['mode'] ?? '') === $mode
                && $ts > 0
                && (time() - $ts) <= 300;
        } catch (\Throwable) {
            $valid = false;
        }

        if (! $valid) {
            return response()->json([
                'ok' => false,
                'message' => 'Confirmation expirée ou invalide — relancez la prévisualisation.',
            ], 422);
        }

        // ponytail: Cache::add is the atomic test-and-set (same primitive refresh()
        // uses) — closes the check-then-set race that a get()+put() would leave open.
        // TTL 1200s = the one authoritative single-flight marker shared by CLI + web
        // + job; the job refreshes it every cycle so a long drain never lapses, and
        // uniqueFor=7200 on the job is the secondary guard against a duplicate dispatch.
        if (! Cache::add('drain:active', true, now()->addSeconds(1200))) {
            return response()->json([
                'ok' => false,
                'message' => 'Un drain est déjà en cours.',
            ], 200);
        }

        $runId = (string) Str::uuid();

        try {
            DrainEnrichmentJob::dispatch($mode, $includeEmpty, $max, $runId);
        } catch (\Throwable $e) {
            Cache::forget('drain:active');

            return response()->json([
                'ok' => false,
                'message' => 'Impossible de lancer le drain. Réessayez.',
            ], 500);
        }

        Cache::forget('provider.hunter.account.v2');

        return response()->json([
            'ok' => true,
            'message' => 'Drain lancé en arrière-plan. Un worker discovery + prospecting est requis.',
        ]);
    }

    /**
     * French cost sentence for the confirm dialog. Provider names stay out of
     * admin copy — generic "crédits" only.
     *
     * @param  array{companies_eligible: int, company_credits: float, contacts_unverified: int, contact_credits: float, total_credits: float}  $estimate
     */
    private function drainSummary(string $mode, array $estimate): string
    {
        $companies = (int) $estimate['companies_eligible'];
        $companyCredits = (float) $estimate['company_credits'];
        $contacts = (int) $estimate['contacts_unverified'];
        $contactCredits = (float) $estimate['contact_credits'];
        $total = (float) $estimate['total_credits'];

        return match ($mode) {
            'companies' => "{$companies} entreprise(s) à enrichir — environ {$companyCredits} crédit(s) de découverte consommé(s).",
            'verify' => "{$contacts} email(s) à vérifier — environ {$contactCredits} crédit(s) consommé(s).",
            default => "{$companies} entreprise(s) à enrichir puis vérification des emails non vérifiés (recalculée après enrichissement, {$contacts} aujourd’hui) — environ {$total} crédit(s) au total. La vérification couvre aussi les emails découverts pendant l’enrichissement ; le coût réel peut dépasser l’estimation.",
        };
    }

    /**
     * Per-provider call-outcome + burn-rate panel.
     *
     * Discovery (serpapi) units come from discovery_runs.searches_consumed via
     * DiscoveryQuotaService::usedInPeriod() — NOT provider_calls.consumed_units,
     * which settles at 0 on the cursor-backed success path (see
     * CompanyDiscoveryService::appendPage(), ~line 1365: "the legacy DiscoveryRun
     * counter above remains the sole quota debit; the provider ledger is an
     * idempotent transport audit"). Contacts (hunter) units ARE real, correctly
     * weighted provider_calls.consumed_units. Call outcomes (succeeded/in_flight/
     * failed) are unaffected by that split and come from provider_calls for both.
     *
     * @param  array<string, mixed>|null  $serpapi
     * @param  array<string, mixed>|null  $hunter
     * @return array<string, array{
     *     label: string,
     *     has_calls: bool,
     *     units_7d: float,
     *     units_30d: float,
     *     failed_30d: int,
     *     in_flight_30d: int,
     *     exhaustion: ?string,
     *     operations: list<array{label: string, calls: int, succeeded: int, in_flight: int, failed: int, units_30d: float}>
     * }>
     */
    private function consumption(?array $serpapi, ?array $hunter): array
    {
        $since30 = now()->subDays(30);
        $since7 = now()->subDays(7);

        $rows = ProviderCall::query()
            ->where('created_at', '>=', $since30)
            ->selectRaw('provider, operation')
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw("SUM(CASE WHEN status = 'succeeded' THEN 1 ELSE 0 END) as succeeded")
            // ponytail: 'reserved' is the provider_calls migration's column default
            // and 'running' is the in-progress state ProviderCallLedger::execute()
            // transitions rows through — both belong in the in-flight bucket, or
            // these columns silently stop summing to COUNT(*).
            ->selectRaw("SUM(CASE WHEN status IN ('reserved','running','pending','retryable') THEN 1 ELSE 0 END) as in_flight")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed")
            ->selectRaw('COALESCE(SUM(consumed_units), 0) as units_30d')
            // ponytail: getBindings() flattens select-bindings before where-bindings,
            // so this `?` binds correctly against the CASE here even though the
            // where() below is declared after it in SQL clause order, not call order.
            // Never "tidy" this into DB::raw() + addBinding() — that ordering is
            // load-bearing, not incidental.
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? THEN consumed_units ELSE 0 END), 0) as units_7d', [$since7])
            ->groupBy('provider', 'operation')
            ->orderByDesc('calls')
            // ponytail: provider_calls has no index on created_at (only `status` and
            // the (provider, operation, idempotency_key) unique), so this 30-day
            // aggregate full-scans the table. Fine at current volume — add
            // index(['provider', 'created_at']) if this grows toward ~1M rows.
            ->get();

        $byProvider = $rows->groupBy('provider');
        $quota = app(DiscoveryQuotaService::class);
        $today = $quota->today();
        $windowEnd = $today->copy()->addDay();

        $hunterRows = $byProvider->get('hunter', collect());
        // ponytail: email_verifier is excluded from the hunter units total — it bills
        // Hunter's separate "verifications" meter, not the "searches" meter this card's
        // Épuisement estimé projects against (see the blade's already-separate Recherches
        // de contacts / Vérifications sections). Delete this filter rather than generalize
        // it if that assumption turns out false.
        $hunterSearchRows = $hunterRows->where('operation', '!=', 'email_verifier');

        return [
            'serpapi' => $this->consumptionBlock(
                'Capacité découverte',
                $byProvider->get('serpapi', collect()),
                (float) $quota->usedInPeriod($today->copy()->subDays(6), $windowEnd),
                (float) $quota->usedInPeriod($today->copy()->subDays(29), $windowEnd),
                isset($serpapi['total_searches_left']) ? max(0, (int) $serpapi['total_searches_left']) : null,
            ),
            'hunter' => $this->consumptionBlock(
                'Capacité contacts',
                $hunterRows,
                round((float) $hunterSearchRows->sum('units_7d'), 2),
                round((float) $hunterSearchRows->sum('units_30d'), 2),
                isset($hunter['searches_available']) ? max(0, (int) $hunter['searches_available']) : null,
            ),
        ];
    }

    /**
     * @param  Collection<int, ProviderCall>  $operationRows  Raw provider_calls rows
     *         for this provider — drives has_calls/failed/in_flight and, per row,
     *         the per-operation table.
     */
    private function consumptionBlock(
        string $label,
        Collection $operationRows,
        float $units7d,
        float $units30d,
        ?int $remaining,
    ): array {
        return [
            'label' => $label,
            'has_calls' => $operationRows->isNotEmpty(),
            'units_7d' => $units7d,
            'units_30d' => $units30d,
            'failed_30d' => (int) $operationRows->sum('failed'),
            'in_flight_30d' => (int) $operationRows->sum('in_flight'),
            'exhaustion' => $this->projectExhaustion($remaining, $units30d),
            'operations' => $operationRows->map(fn ($row): array => [
                'label' => self::OPERATION_LABELS[$row->operation] ?? $row->operation,
                'calls' => (int) $row->calls,
                'succeeded' => (int) $row->succeeded,
                'in_flight' => (int) $row->in_flight,
                'failed' => (int) $row->failed,
                'units_30d' => (float) $row->units_30d,
            ])->values()->all(),
        ];
    }

    /**
     * Days-to-exhaustion projection: remaining ÷ (30-day units / 30), from today.
     * Returns null (never a fabricated date) when remaining is unknown or the
     * 30-day burn rate is zero — the blade's stat-card renders its own empty state.
     */
    private function projectExhaustion(?int $remaining, float $units30d): ?string
    {
        if ($remaining === null || $units30d <= 0) {
            return null;
        }

        $daysLeft = $remaining / ($units30d / 30);

        if ($daysLeft > 365) {
            return 'Au-delà de 12 mois';
        }

        return now()->addDays((int) round($daysLeft))->format('d/m/Y');
    }
}
