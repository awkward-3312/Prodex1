<!DOCTYPE html>
@php
    $isRtl = in_array(app()->getLocale(), ['ar', 'he', 'fa', 'ur']);
    $generalSettings = \App\Models\Central\GeneralSetting::instance();
    $appName = $generalSettings->app_name ?: 'Stocky';
    $logoUrl = $generalSettings->getLogoUrl();
@endphp
<html lang="{{ app()->getLocale() }}" @if($isRtl) dir="rtl" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('landing.terms_and_conditions') }} — {{ $appName }}</title>
    @php
        $seoTitle = __('landing.terms_and_conditions') . ' — ' . $appName;
        $seoDescription = __('landing.terms_meta_description');
        $seoCanonicalUrl = rtrim(config('seo.base_url', 'https://prodexhub.cloud'), '/') . '/terms-conditions';
        $seoType = 'article';
    @endphp
    @include('central.partials.seo-head')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="{{ asset('assets_super/css/inter.css') }}" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
    <link href="{{ asset('assets_super/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/landing.css') }}" rel="stylesheet">
    @include('central.partials.landing-font')
</head>
<body class="privacy-policy-body">

    <nav class="navbar navbar-expand-lg navbar-landing fixed-top" id="mainNav">
        <div class="container">
            <a class="navbar-brand" href="{{ route('central.welcome') }}">
                <span class="brand-mark">
                    @if($logoUrl)
                        <img src="{{ $logoUrl }}" alt="" width="112" height="28">
                    @else
                        <span class="brand-icon">{{ strtoupper(substr($appName, 0, 1)) }}</span>
                    @endif
                </span>
                <span class="brand-text">{{ $appName }}</span>
            </a>
            <a class="nav-btn nav-btn-ghost" href="{{ route('central.welcome') }}">
                <i class="bi bi-arrow-left"></i> {{ __('landing.home') }}
            </a>
        </div>
    </nav>

    <main class="privacy-page" id="privacy-main">
        <header class="privacy-hero">
            <div class="container">
                <div class="privacy-hero-inner">
                    <p class="privacy-hero-eyebrow">{{ $appName }}</p>
                    <h1>{{ __('landing.terms_and_conditions') }}</h1>
                    <p class="privacy-updated">
                        <i class="bi bi-calendar3" aria-hidden="true"></i>
                        <span>{{ __('landing.terms_last_updated') }} · {{ $terms->last_updated ? $terms->last_updated->format('F j, Y') : now()->format('F j, Y') }}</span>
                    </p>
                </div>
            </div>
        </header>

        @php
            // If a verified legal entity has been configured (config/legal.php,
            // set once the business owner confirms it), append the full legal
            // identification to the provider section instead of only the
            // "contact us to request it" language baked into the base text.
            $providerText = __('landing.terms_provider_text');
            if ($legalEntityName = config('legal.entity_name')) {
                $identityLine = __('landing.terms_provider_identified_text', ['entity' => $legalEntityName]);
                if ($legalAddress = config('legal.address')) {
                    $identityLine .= ' ' . __('landing.terms_provider_address_text', ['address' => $legalAddress]);
                }
                if ($legalTaxId = config('legal.tax_id')) {
                    $identityLine .= ' ' . __('landing.terms_provider_tax_id_text', ['tax_id' => $legalTaxId]);
                }
                if ($legalEmail = config('legal.legal_email') ?: optional($footer)->contact_email) {
                    $identityLine .= ' ' . __('landing.terms_provider_legal_email_text', ['email' => $legalEmail]);
                }
                $providerText .= "\n\n" . $identityLine;
            }

            $sections = [
                ['id' => 'provider',        'title' => __('landing.terms_provider_title'),        'content' => $providerText],
                ['id' => 'definitions',     'title' => __('landing.terms_definitions_title'),     'content' => __('landing.terms_definitions_text')],
                ['id' => 'acceptance',      'title' => __('landing.terms_acceptance_title'),      'content' => $terms->acceptance],
                ['id' => 'capacity',        'title' => __('landing.terms_capacity_title'),        'content' => __('landing.terms_capacity_text')],
                ['id' => 'use-license',     'title' => __('landing.terms_license_title'),         'content' => $terms->use_license],
                ['id' => 'accounts',        'title' => __('landing.terms_accounts_title'),        'content' => $terms->user_accounts],
                ['id' => 'multitenancy',    'title' => __('landing.terms_multitenancy_title'),    'content' => __('landing.terms_multitenancy_text')],
                ['id' => 'plans-pricing',   'title' => __('landing.terms_plans_pricing_title'),   'content' => __('landing.terms_plans_pricing_text')],
                ['id' => 'trial',           'title' => __('landing.terms_trial_title'),           'content' => __('landing.terms_trial_text')],
                ['id' => 'payments',        'title' => __('landing.terms_payments_title'),        'content' => $terms->payments],
                ['id' => 'billing-failures', 'title' => __('landing.terms_billing_failures_title'), 'content' => __('landing.terms_billing_failures_text')],
                ['id' => 'cancellation',    'title' => __('landing.terms_cancellation_title'),    'content' => __('landing.terms_cancellation_text')],
                ['id' => 'resumption',      'title' => __('landing.terms_resumption_title'),      'content' => __('landing.terms_resumption_text')],
                ['id' => 'refunds',         'title' => __('landing.terms_refunds_ref_title'),     'content' => __('landing.terms_refunds_ref_text')],
                ['id' => 'prohibited',      'title' => __('landing.terms_prohibited_title'),      'content' => $terms->prohibited],
                ['id' => 'ip',              'title' => __('landing.terms_ip_title'),              'content' => $terms->intellectual_property],
                ['id' => 'customer-data',   'title' => __('landing.terms_customer_data_title'),   'content' => __('landing.terms_customer_data_text')],
                ['id' => 'availability',    'title' => __('landing.terms_availability_title'),    'content' => __('landing.terms_availability_text')],
                ['id' => 'integrations',    'title' => __('landing.terms_integrations_title'),    'content' => __('landing.terms_integrations_text')],
                ['id' => 'security',        'title' => __('landing.terms_security_title'),        'content' => __('landing.terms_security_text')],
                ['id' => 'fiscal',          'title' => __('landing.terms_fiscal_title'),          'content' => __('landing.terms_fiscal_text')],
                ['id' => 'warranties',      'title' => __('landing.terms_warranties_title'),      'content' => __('landing.terms_warranties_text')],
                ['id' => 'liability',       'title' => __('landing.terms_liability_title'),       'content' => $terms->liability],
                ['id' => 'indemnification', 'title' => __('landing.terms_indemnification_title'), 'content' => __('landing.terms_indemnification_text')],
                ['id' => 'force-majeure',   'title' => __('landing.terms_force_majeure_title'),   'content' => __('landing.terms_force_majeure_text')],
                ['id' => 'termination',     'title' => __('landing.terms_termination_title'),     'content' => __('landing.terms_termination_text')],
                ['id' => 'changes',         'title' => __('landing.terms_changes_title'),         'content' => __('landing.terms_changes_text')],
                ['id' => 'governing-law',   'title' => __('landing.terms_law_title'),             'content' => $terms->governing_law],
                ['id' => 'misc',            'title' => __('landing.terms_misc_title'),            'content' => __('landing.terms_misc_text')],
                ['id' => 'contact',         'title' => __('landing.terms_contact_title'),         'content' => __('landing.terms_contact_text')],
            ];
            $activeSections = array_filter($sections, fn($s) => !empty($s['content']));
        @endphp

        <div class="container">
            <div class="privacy-content">
                <nav class="privacy-toc" aria-label="{{ __('landing.quick_links') }}">
                    <p class="privacy-toc__label">{{ __('landing.quick_links') }}</p>
                    <ul>
                        @foreach($activeSections as $section)
                            <li><a href="#{{ $section['id'] }}"><i class="bi bi-chevron-right privacy-toc__chev" aria-hidden="true"></i> {{ $section['title'] }}</a></li>
                        @endforeach
                    </ul>
                </nav>

                <div class="privacy-sections">
                    @foreach($activeSections as $section)
                        <section id="{{ $section['id'] }}" class="privacy-section">
                            <h2>{{ $section['title'] }}</h2>
                            <div class="privacy-section__body">{!! nl2br(e($section['content'])) !!}</div>

                            @if($section['id'] === 'contact' && $footer)
                                <div class="privacy-contact-info">
                                    @if($footer->contact_email)
                                        <a href="mailto:{{ $footer->contact_email }}">
                                            <i class="bi bi-envelope"></i> {{ $footer->contact_email }}
                                        </a>
                                    @endif
                                    @if($footer->contact_phone)
                                        <a href="tel:{{ $footer->contact_phone }}">
                                            <i class="bi bi-telephone"></i> {{ $footer->contact_phone }}
                                        </a>
                                    @endif
                                    @if($footer->address)
                                        <span><i class="bi bi-geo-alt"></i> {{ $footer->address }}</span>
                                    @endif
                                </div>
                            @endif
                        </section>
                    @endforeach
                </div>
            </div>
        </div>
    </main>

    <footer class="privacy-footer">
        <div class="container">
            <div class="privacy-footer__inner">
                <p class="privacy-footer__legal">{{ $footer->copyright_text ?? '© ' . date('Y') . ' ' . $appName . '. All rights reserved.' }}</p>
            </div>
        </div>
    </footer>

    <script src="{{ asset('assets_super/js/privacy-policy.js') }}"></script>
    @include('central.partials.analytics')
</body>
</html>
