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
    <title>{{ __('landing.refund_policy') }} — {{ $appName }}</title>
    @php
        $seoTitle = __('landing.refund_policy') . ' — ' . $appName;
        $seoDescription = __('landing.refund_meta_description');
        $seoCanonicalUrl = rtrim(config('seo.base_url', 'https://prodexhub.cloud'), '/') . '/refund-policy';
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
                    <h1>{{ __('landing.refund_policy') }}</h1>
                    <p class="privacy-updated">
                        <i class="bi bi-calendar3" aria-hidden="true"></i>
                        <span>{{ __('landing.refund_last_updated') }} · {{ $refund->last_updated ? $refund->last_updated->format('F j, Y') : now()->format('F j, Y') }}</span>
                    </p>
                </div>
            </div>
        </header>

        @php
            $sections = [
                ['id' => 'overview',             'title' => __('landing.refund_overview_title'),             'content' => $refund->overview],
                ['id' => 'subscriptions-trials', 'title' => __('landing.refund_subscriptions_trials_title'), 'content' => $refund->subscriptions_trials],
                ['id' => 'cancellations',        'title' => __('landing.refund_cancellations_title'),        'content' => $refund->cancellations],
                ['id' => 'billing-errors',       'title' => __('landing.refund_billing_errors_title'),       'content' => $refund->billing_errors],
                ['id' => 'eligibility',          'title' => __('landing.refund_eligibility_title'),          'content' => $refund->refund_eligibility],
                ['id' => 'chargebacks',          'title' => __('landing.refund_chargebacks_title'),          'content' => $refund->chargebacks],
                ['id' => 'how-to-request',       'title' => __('landing.refund_how_to_request_title'),       'content' => $refund->how_to_request],
                ['id' => 'payment-processor',    'title' => __('landing.refund_payment_processor_title'),    'content' => $refund->payment_processor],
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

                            @if($section['id'] === 'how-to-request' && $footer)
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
