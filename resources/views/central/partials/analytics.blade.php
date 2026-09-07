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
<script
    src="{{ asset('assets_super/js/prodex-consent.js') }}"
    data-ga-id="{{ $gaEmit }}"
    data-consent-version="{{ $consentVersion }}"
    data-privacy-url="{{ $privacyUrl }}"
    defer></script>
