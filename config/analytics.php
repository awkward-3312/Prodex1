<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Google Analytics 4
    |--------------------------------------------------------------------------
    |
    | The GA4 Measurement ID (format: G-XXXXXXXXXX). This value is NOT a
    | secret — the browser sees it — but it is still read from the environment
    | so it is never hard-coded and can differ per environment. When empty the
    | analytics partial renders nothing at all.
    |
    | IMPORTANT: even when set, no Google script is loaded and no hit is sent
    | until the visitor has granted consent to the "analytics" cookie
    | category. See resources/views/central/partials/analytics.blade.php and
    | public/assets_super/js/prodex-consent.js.
    |
    */

    'ga_measurement_id' => env('GA_MEASUREMENT_ID', ''),

    /*
    | Only load analytics in these environments even when an ID is present.
    | Keeps local/staging noise out of the production property.
    */
    'enabled_environments' => ['production'],

    /*
    | Consent bookkeeping. Bump the version when the cookie categories or their
    | meaning change so returning visitors are asked again.
    */
    'consent_version' => 2,
];
