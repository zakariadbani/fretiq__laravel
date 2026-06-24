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
    | Discovery driver
    |--------------------------------------------------------------------------
    | local  → stub / no external calls (default in dev)
    | live   → SerpAPI + Hunter real API calls
    */
    'discovery_driver'  => env('DISCOVERY_DRIVER', 'local'),

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

];
