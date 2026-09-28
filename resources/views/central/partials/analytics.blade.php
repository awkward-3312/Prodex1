{{--
    Consent-gated analytics loader for the public site.

    Loads public/assets_super/js/prodex-consent.js which owns the cookie
    decision AND is the only thing that may inject Google Analytics — and only
    after the visitor has granted the "analytics" category. Renders the GA id
    into a data-attribute (public value, never a secret); with no id, or
    outside the enabled environments, no GA id is emitted at all so nothing can
    load it.
--}}
@php
    $gaId = (string) config('analytics.ga_measurement_id', '');
    $gaEnvOk = in_array(app()->environment(), (array) config('analytics.enabled_environments', ['production']), true);
    $gaEmit = $gaId !== '' && preg_match('/^G-[A-Z0-9]{6,}$/', $gaId) && $gaEnvOk ? $gaId : '';
    $consentVersion = (int) config('analytics.consent_version', 1);
    $privacyUrl = \Illuminate\Support\Facades\Route::has('central.privacy-policy')
        ? route('central.privacy-policy')
        : '/privacy-policy';
@endphp
@php
    // Textos del consentimiento (sistema i18n existente: resources/lang/*/landing.php).
    $consentI18n = [
        'title' => __('landing.consent_title'),
        'text' => __('landing.consent_text'),
        'policy' => __('landing.privacy_policy'),
        'accept' => __('landing.consent_accept'),
        'reject' => __('landing.consent_reject'),
        'customize' => __('landing.consent_customize'),
        'save' => __('landing.consent_save'),
        'prefsTitle' => __('landing.consent_prefs_title'),
        'necessary' => __('landing.consent_necessary'),
        'necessaryDesc' => __('landing.consent_necessary_desc'),
        'analytics' => __('landing.consent_analytics'),
        'analyticsDesc' => __('landing.consent_analytics_desc'),
        'onlyNecessary' => __('landing.consent_only_necessary'),
        'close' => __('landing.consent_close'),
    ];
@endphp
<link rel="stylesheet" href="{{ asset('assets_super/css/prodex-consent.css') }}">
<script type="application/json" id="prodex-consent-i18n">{!! json_encode($consentI18n, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
<script
    src="{{ asset('assets_super/js/prodex-consent.js') }}"
    data-ga-id="{{ $gaEmit }}"
    data-consent-version="{{ $consentVersion }}"
    data-privacy-url="{{ $privacyUrl }}"
    defer></script>
