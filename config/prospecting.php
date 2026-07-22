<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cold-send gate
    |--------------------------------------------------------------------------
    | Keep false in dev/staging. Flip to true only after legal sign-off +
    | full compliance prerequisites (SPF/DKIM/DMARC, List-Unsubscribe,
    | bounce handling) are confirmed.
    */
    'cold_send_enabled' => env('PROSPECTING_COLD_SEND_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Free-webmail domains
    |--------------------------------------------------------------------------
    | Emails at these domains are classified as email_kind='personal' (excluded
    | from cold sends). Everything else is classified as email_kind='role'
    | (corporate / deliverable). Used by App\Support\EmailKind::classify().
    */
    'freemail_domains' => [
        'gmail.com','googlemail.com','yahoo.com','yahoo.fr','yahoo.co.uk',
        'hotmail.com','hotmail.fr','outlook.com','outlook.fr','live.com','live.fr',
        'msn.com','aol.com','icloud.com','me.com','mac.com','gmx.com','gmx.fr','gmx.net',
        'mail.com','proton.me','protonmail.com','tutanota.com','zoho.com',
        'orange.fr','wanadoo.fr','free.fr','sfr.fr','laposte.net','bbox.fr','neuf.fr',
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider capacity (fixed monthly plans currently paid)
    |--------------------------------------------------------------------------
    | Monthly allowance of the FIXED provider plans TCL currently pays for —
    | NOT a live API balance fetch (extension point for later: fetch real
    | provider usage instead of these static config values).
    |
    | Null = capacity card hidden on admin/packages (no provider plan declared
    | yet, or intentionally not tracked).
    |
    | Update these two env values whenever the provider plan changes:
    |   - discovery capacity  = discovery-provider searches/mo (today: 1000, Starter plan)
    |   - enrich capacity     = enrichment-provider credits/mo (today: 2000, Starter plan)
    |
    | Budget note: each discovery run burns up to max_queries_per_run
    | (config('services.serpapi.max_queries_per_run')) searches regardless of
    | companies found — daily runs × max_queries_per_run can exceed the
    | discovery-provider Starter allowance (~1000/mo) if run daily at 40/run.
    | Lower max_queries_per_run or upgrade the provider plan if the estimated
    | monthly search count creeps past the declared capacity.
    */
    'provider_discovery_monthly_capacity' => env('PROVIDER_DISCOVERY_MONTHLY_CAPACITY') !== null ? (int) env('PROVIDER_DISCOVERY_MONTHLY_CAPACITY') : null,
    'provider_enrich_monthly_capacity'    => env('PROVIDER_ENRICH_MONTHLY_CAPACITY') !== null ? (int) env('PROVIDER_ENRICH_MONTHLY_CAPACITY') : null,

    /*
    |--------------------------------------------------------------------------
    | Public site — campaign template builder CTA targets
    |--------------------------------------------------------------------------
    | base_url is the public TCL Transport site the "builder" email templates
    | link to. cta_intents are the fixed set of call-to-action destinations the
    | builder lets the user pick from — each intent's absolute URL is base_url
    | joined with its relative `path` (empty string = base_url itself). Adding
    | a new intent (or changing a path) never requires touching PHP code.
    |
    | URL joining (App\Services\Campaign\TemplateBuilder\SectionCatalog::ctaIntents())
    | rtrim()s base_url and ltrim()s each path before concatenating, so neither
    | a trailing slash on base_url nor a leading slash on path can produce "//".
    |
    | ⚠ PLACEHOLDERS — the paths below are best-effort defaults, not confirmed
    | production destinations. The real quotation and chatbot landing paths on
    | tcltransport.com MUST be confirmed (and PROSPECTING_SITE_QUOTE_PATH /
    | PROSPECTING_SITE_CHATBOT_PATH set accordingly) before any cold send that
    | relies on these CTA links reaching a real page. `quote` defaults to
    | 'contact' — the closest static equivalent to the reference templates'
    | https://www.tcl.ma/contact CTA (docs/mail templates/standard/). `chatbot`
    | has no known equivalent yet and defaults to '' (site root) until one exists.
    */
    'site' => [
        'base_url' => env('PROSPECTING_SITE_BASE_URL', 'https://tcltransport.com/'),

        'cta_intents' => [
            'quote' => [
                'label' => 'Demander une cotation',
                'path'  => env('PROSPECTING_SITE_QUOTE_PATH', 'contact'),
            ],
            'chatbot' => [
                'label' => 'Poser une question (chatbot)',
                'path'  => env('PROSPECTING_SITE_CHATBOT_PATH', ''),
            ],
        ],
    ],

];
