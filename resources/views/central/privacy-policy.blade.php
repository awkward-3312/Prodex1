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
    <title>{{ __('landing.privacy_policy') }} — {{ $appName }}</title>
    @php
        $faviconUrl = ($seo && $seo->favicon) ? asset($seo->favicon) : $generalSettings->getFaviconUrl();
    @endphp
    @if($faviconUrl)
        <link rel="icon" href="{{ $faviconUrl }}">
    @endif
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="{{ asset('assets_super/css/inter.css') }}" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
    <link href="{{ asset('assets_super/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/landing.css') }}" rel="stylesheet">
</head>
<body class="privacy-policy-body">

    <nav class="navbar navbar-expand-lg navbar-landing fixed-top" id="mainNav">
        <div class="container">
            <a class="navbar-brand" href="{{ route('central.welcome') }}">
                <span class="brand-mark">
                    @if($logoUrl)
                        <img src="{{ $logoUrl }}" alt="{{ $appName }}">
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
                    <h1>{{ __('landing.privacy_policy') }}</h1>
                    <p class="privacy-updated">
                        <i class="bi bi-calendar3" aria-hidden="true"></i>
                        <span>{{ __('landing.privacy_last_updated') }} · {{ $privacy->last_updated ? $privacy->last_updated->format('F j, Y') : now()->format('F j, Y') }}</span>
                    </p>
                </div>
            </div>
        </header>

        @php
            $sections = [
                ['id' => 'introduction',  'title' => __('landing.privacy_intro_title'),        'content' => $privacy->introduction],
                ['id' => 'data-collect',  'title' => __('landing.privacy_collect_title'),      'content' => $privacy->data_collection],
                ['id' => 'data-use',      'title' => __('landing.privacy_use_title'),          'content' => $privacy->data_usage],
                ['id' => 'cookies',       'title' => __('landing.privacy_cookies_title'),      'content' => $privacy->cookies_usage],
                ['id' => 'third-party',   'title' => __('landing.privacy_third_party_title'),  'content' => $privacy->third_party],
                ['id' => 'protection',    'title' => __('landing.privacy_protection_title'),   'content' => $privacy->data_protection],
                ['id' => 'rights',        'title' => __('landing.privacy_rights_title'),       'content' => $privacy->user_rights],
                ['id' => 'contact',       'title' => __('landing.privacy_contact_title'),      'content' => $privacy->contact_info],
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
</body>
</html>
