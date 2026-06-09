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

];
