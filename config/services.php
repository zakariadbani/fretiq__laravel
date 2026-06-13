<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_CALLBACK_URL'),
    ],

    'facebook' => [
        'client_id'     => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect'      => '/auth/redirect/facebook',
    ],

    /*
    |--------------------------------------------------------------------------
    | Zoho (CRM + Campaigns)
    |--------------------------------------------------------------------------
    | driver=local  → stub/fake driver (default in dev/test)
    | driver=zoho   → live Zoho API calls (prod)
    */
    'zoho' => [
        'driver'      => env('ZOHO_CAMPAIGNS_DRIVER', 'local'),
        'crm_driver'  => env('ZOHO_CRM_DRIVER', 'local'),
        'crm' => [
            'client_id'     => env('ZOHO_CRM_CLIENT_ID'),
            'client_secret' => env('ZOHO_CRM_CLIENT_SECRET'),
            'refresh_token' => env('ZOHO_CRM_REFRESH_TOKEN'),
            'api_url'       => env('ZOHO_CRM_API_URL', 'https://www.zohoapis.com/crm/v2'),
            'accounts_url'  => env('ZOHO_CRM_ACCOUNTS_URL', env('ZOHO_ACCOUNTS_URL', 'https://accounts.zoho.com')),
            'version'       => env('ZOHO_CRM_VERSION', 'v8'),
            'module_versions' => json_decode(env('ZOHO_CRM_MODULE_VERSIONS', '{}'), true) ?: [],
        ],
        'campaigns' => [
            // Phase 5 — credentials blank until Zoho Campaigns account is provisioned
            'client_id'     => env('ZOHO_CAMPAIGNS_CLIENT_ID'),
            'client_secret' => env('ZOHO_CAMPAIGNS_CLIENT_SECRET'),
            'refresh_token' => env('ZOHO_CAMPAIGNS_REFRESH_TOKEN'),
        ],
        'accounts_url'      => env('ZOHO_ACCOUNTS_URL', 'https://accounts.zoho.com'),
        'default_from_email' => env('ZOHO_DEFAULT_FROM_EMAIL', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Discovery APIs
    |--------------------------------------------------------------------------
    */
    'serpapi' => [
        'api_key'              => env('SERPAPI_API_KEY'),
        'driver'               => env('DISCOVERY_DRIVER', 'local'),
        // D10: cap per-run query count to avoid UE-27 × sectors multiplication (700+ queries).
        'max_queries_per_run'  => env('SERPAPI_MAX_QUERIES_PER_RUN', 40),
    ],

    'hunter' => [
        'api_key' => env('HUNTER_API_KEY'),
        'driver'  => env('DISCOVERY_DRIVER', 'local'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model'   => env('GEMINI_MODEL', 'gemini-2.0-flash'),
    ],

    'scoring' => [
        'driver' => env('SCORING_DRIVER', 'heuristic'), // heuristic|gemini
    ],

];
