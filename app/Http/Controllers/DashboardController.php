<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Demande;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        // ── Counts ────────────────────────────────────────────────────────────
        $companiesCount = Company::count();
        $contactsCount  = Contact::count();

        // "Active" = is_active=true (sole live gate)
        $activeCampaigns  = Campaign::where('is_active', true)->count();
        $pendingCampaigns = Campaign::where('is_active', false)->count();

        // Total emails sent (recipients whose status = 'sent' or have a sent_at)
        $totalSent = CampaignRecipient::whereNotNull('sent_at')->count();

        // Opened / clicked (from recipients table)
        $totalOpened  = CampaignRecipient::whereNotNull('opened_at')->count();
        $totalClicked = CampaignRecipient::whereNotNull('clicked_at')->count();
        $totalReplied = CampaignRecipient::whereNotNull('replied_at')->count();

        // Demandes
        $demandesCount = Demande::count();

        // ── Rates (guard divide-by-zero) ──────────────────────────────────────
        $openRate       = $totalSent > 0 ? round($totalOpened  / $totalSent * 100, 1) : 0;
        $clickRate      = $totalSent > 0 ? round($totalClicked / $totalSent * 100, 1) : 0;
        $conversionRate = $totalSent > 0 ? round($demandesCount / $totalSent * 100, 2) : 0;

        // ── Funnel stages ─────────────────────────────────────────────────────
        // Découvertes = all contacts, Contactés = sent, Ouverts, Cliqués, Répondus, Demandes
        $funnelStages = [
            'Découvertes' => $contactsCount,
            'Contactés'   => $totalSent,
            'Ouverts'     => $totalOpened,
            'Cliqués'     => $totalClicked,
            'Répondus'    => $totalReplied,
            'Demandes'    => $demandesCount,
        ];

        // ── Engagement weekly time-series (last 4 weeks) ──────────────────────
        // Group campaign_recipients by ISO week; pull last 4 active weeks
        $engagementRaw = CampaignRecipient::select(
                DB::raw('YEARWEEK(sent_at, 3) as yw'),
                DB::raw('SUM(opened_at IS NOT NULL) as opens'),
                DB::raw('SUM(clicked_at IS NOT NULL) as clicks'),
                DB::raw('SUM(replied_at IS NOT NULL) as replies')
            )
            ->whereNotNull('sent_at')
            ->where('sent_at', '>=', now()->subWeeks(4))
            ->groupBy('yw')
            ->orderBy('yw')
            ->get();

        // If no data at all, produce 4 zero-filled entries so charts still render
        if ($engagementRaw->isEmpty()) {
            $engagementWeeks   = ['Sem. 1', 'Sem. 2', 'Sem. 3', 'Sem. 4'];
            $engagementOpens   = [0, 0, 0, 0];
            $engagementClicks  = [0, 0, 0, 0];
            $engagementReplies = [0, 0, 0, 0];
        } else {
            $engagementWeeks   = $engagementRaw->pluck('yw')->map(fn ($yw) => 'Sem. ' . substr($yw, 4))->toArray();
            $engagementOpens   = $engagementRaw->pluck('opens')->map(fn ($v) => (int) $v)->toArray();
            $engagementClicks  = $engagementRaw->pluck('clicks')->map(fn ($v) => (int) $v)->toArray();
            $engagementReplies = $engagementRaw->pluck('replies')->map(fn ($v) => (int) $v)->toArray();
        }

        // ── Top campaigns ─────────────────────────────────────────────────────
        // Join campaigns → campaign_runs → recipients/demandes, order by demandes desc
        $topCampaigns = Campaign::select(
                'campaigns.id',
                'campaigns.name',
                DB::raw('SUM(cr.stats_sent) as total_sent'),
                DB::raw('SUM(cr.stats_opened) as total_opened'),
                DB::raw('COUNT(DISTINCT d.id) as total_demandes')
            )
            ->leftJoin('campaign_runs as cr', 'cr.campaign_id', '=', 'campaigns.id')
            ->leftJoin('demandes as d', 'd.campaign_id', '=', 'campaigns.id')
            ->groupBy('campaigns.id', 'campaigns.name')
            ->orderByDesc('total_demandes')
            ->limit(5)
            ->get()
            ->map(function ($row) {
                $sent       = (int) $row->total_sent;
                $opened     = (int) $row->total_opened;
                $openRate   = $sent > 0 ? round($opened / $sent * 100) : 0;
                return [
                    'id'        => $row->id,
                    'name'      => $row->name,
                    'sent'      => $sent,
                    'open_rate' => $openRate,
                    'demandes'  => (int) $row->total_demandes,
                ];
            });

        return view('pages/dashboards.index', compact(
            'companiesCount',
            'contactsCount',
            'activeCampaigns',
            'pendingCampaigns',
            'totalSent',
            'openRate',
            'clickRate',
            'demandesCount',
            'conversionRate',
            'funnelStages',
            'engagementWeeks',
            'engagementOpens',
            'engagementClicks',
            'engagementReplies',
            'topCampaigns'
        ));
    }
}
