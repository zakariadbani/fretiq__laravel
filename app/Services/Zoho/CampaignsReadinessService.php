<?php

namespace App\Services\Zoho;

/**
 * CampaignsReadinessService — preflight checklist for the Zoho Campaigns real driver.
 *
 * Returns a structured checklist of prerequisites that must ALL be true before
 * switching config('services.zoho.driver') from 'local' to 'zoho'.
 *
 * Display this on the Zoho admin screen via ZohoController::index().
 *
 * Each item represents a hard gate defined in compliance-deliverability.md §3
 * or campaign-automation.md §7. Items that are infrastructure/manual (SPF/DKIM/DMARC,
 * bounce handling, cold legal sign-off) default to false and must be set via config.
 *
 * @see compliance-deliverability.md §3 (hard deliverability gate)
 * @see campaign-automation.md §7 (driver boundary)
 */
class CampaignsReadinessService
{
    public function __construct(
        private readonly ZohoAuthService $authService,
    ) {}

    /**
     * Run the preflight checklist and return a structured result.
     *
     * @return array{
     *     items: array<string, array{label: string, status: bool, note: string}>,
     *     ready: bool,
     *     driver: string
     * }
     */
    public function check(): array
    {
        $driver = config('services.zoho.driver', 'local');

        $items = [

            // ── OAuth ──────────────────────────────────────────────────────────
            'oauth_configured' => [
                'label'  => 'OAuth Zoho Campaigns configuré',
                'status' => ! empty(config('services.zoho.campaigns.refresh_token')),
                'note'   => 'config("services.zoho.campaigns.refresh_token") doit être renseigné dans .env',
            ],

            // ── Driver active ──────────────────────────────────────────────────
            'driver_zoho' => [
                'label'  => 'Driver campagnes = zoho',
                'status' => $driver === 'zoho',
                'note'   => 'SERVICES_ZOHO_DRIVER=zoho dans .env (laisser "local" jusqu\'aux prérequis complets)',
            ],

            // ── Email infrastructure ───────────────────────────────────────────
            'spf_dkim_dmarc' => [
                'label'  => 'SPF / DKIM / DMARC configurés',
                'status' => (bool) config('prospecting.spf_dkim_dmarc_configured', false),
                'note'   => 'Vérification manuelle — configurer SPF_DKIM_DMARC_CONFIGURED=true une fois validé',
            ],

            // ── List-Unsubscribe ───────────────────────────────────────────────
            'list_unsubscribe' => [
                'label'  => 'List-Unsubscribe implémenté',
                'status' => true, // Implemented in Phase 3 (signed unsubscribe URL + header in CampaignMailable).
                'note'   => 'Implémenté en Phase 3 — route unsubscribe signée active',
            ],

            // ── Bounce handling ────────────────────────────────────────────────
            'bounce_handling' => [
                'label'  => 'Gestion des bounces configurée',
                'status' => (bool) config('prospecting.bounce_handling_configured', false),
                'note'   => 'Phase ultérieure — webhook Zoho bounce + alimentation Suppression table',
            ],

            // ── Cold legal sign-off ────────────────────────────────────────────
            'cold_basis_signed_off' => [
                'label'  => 'Cold send — base légale signée (TCL)',
                'status' => (bool) config('prospecting.cold_send_enabled', false),
                'note'   => 'PROSPECTING_COLD_SEND_ENABLED=true requiert validation légale TCL (RGPD B2B)',
            ],

            // ── Public APP_URL ─────────────────────────────────────────────────
            'public_app_url' => [
                'label'  => 'APP_URL public (hors localhost)',
                'status' => $this->isPublicAppUrl(),
                'note'   => 'APP_URL=' . config('app.url', '') . ' — les pixels de tracking requièrent une URL joignable par le destinataire',
            ],
        ];

        // Overall readiness: ALL items must be true.
        $ready = collect($items)->every(fn ($item) => $item['status'] === true);

        return [
            'items'  => $items,
            'ready'  => $ready,
            'driver' => $driver,
        ];
    }

    /**
     * Dispatch-grade readiness: config checklist plus a real Campaigns OAuth token.
     *
     * @return array{ready: bool, messages: array<int, string>, access_token_checked: bool}
     */
    public function dispatchCheck(): array
    {
        $check = $this->check();
        $messages = [];

        foreach ($check['items'] as $item) {
            if (($item['status'] ?? false) !== true) {
                $messages[] = ($item['label'] ?? 'Prérequis Zoho') . ' : ' . ($item['note'] ?? 'à vérifier.');
            }
        }

        $tokenChecked = false;

        if ($messages === []) {
            try {
                $tokenChecked = trim($this->authService->getAccessToken('campaigns')) !== '';
            } catch (\Throwable $e) {
                $messages[] = 'OAuth Zoho Campaigns expiré ou invalide : reconnectez Zoho Campaigns avant de lancer l’envoi.';
            }
        }

        return [
            'ready' => $messages === [] && $tokenChecked,
            'messages' => $messages,
            'access_token_checked' => $tokenChecked,
        ];
    }
    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Determine whether APP_URL is a reachable public URL (not localhost).
     */
    private function isPublicAppUrl(): bool
    {
        $url = config('app.url', '');

        if (empty($url)) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST) ?? '';

        // Reject localhost, 127.x.x.x, and ::1 — these cannot receive tracking pixels.
        return ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            && ! str_starts_with($host, '127.');
    }
}