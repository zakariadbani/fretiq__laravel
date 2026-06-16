<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Base language
    |--------------------------------------------------------------------------
    | The language in which all campaign templates are authored. Translations
    | are always FROM this language. It is never a translation target.
    */
    'base_language' => 'fr',

    /*
    |--------------------------------------------------------------------------
    | Default language
    |--------------------------------------------------------------------------
    | Returned by LanguageResolver when country is null/empty/unmapped.
    | Keeps French as the safe default for TCL France prospects.
    */
    'default_language' => 'fr',

    /*
    |--------------------------------------------------------------------------
    | Translation targets
    |--------------------------------------------------------------------------
    | Languages to generate when "Traduire avec l'IA" is triggered.
    | Extend with 'de', 'es', etc. as needed; the service rejects base_language.
    */
    'target_languages' => ['en'],

    /*
    |--------------------------------------------------------------------------
    | Francophone countries
    |--------------------------------------------------------------------------
    | ISO-3166-1 alpha-2 codes (uppercase). Contacts from these countries
    | receive the FR base template rather than an EN translation.
    | Add Maghreb / African francophone codes here when needed.
    */
    'francophone_countries' => ['FR', 'BE', 'LU', 'MC', 'CH', 'CA'],

    /*
    |--------------------------------------------------------------------------
    | Unsubscribe footer
    |--------------------------------------------------------------------------
    | Per-language localised text for the compliance footer appended to every
    | campaign email (CampaignMailable / SequenceStepMailable).
    |
    | 'intro'      — sentence displayed before the link.
    | 'link_label' — anchor text of the unsubscribe link.
    |
    | The EN string is hand-authored (never machine-translated) to ensure the
    | RGPD/GDPR B2B legitimate-interest disclosure is legally accurate.
    */
    'footer' => [
        'fr' => [
            'intro'      => 'Vous recevez cet email car vous faites partie de notre liste de contacts professionnels.',
            'link_label' => 'Se désabonner',
        ],
        'en' => [
            'intro'      => 'You are receiving this email because you are part of our professional contact list.',
            'link_label' => 'Unsubscribe',
        ],
    ],

];
