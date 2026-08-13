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
    @include('central.partials.landing-font')
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
                    <h1>{{ __('landing.terms_and_conditions') }}</h1>
                    <p class="privacy-updated">
                        <i class="bi bi-calendar3" aria-hidden="true"></i>
                        <span>{{ __('landing.terms_last_updated') }} · {{ $terms->last_updated ? $terms->last_updated->format('F j, Y') : now()->format('F j, Y') }}</span>
                    </p>
                </div>
            </div>
        </header>

        @php
            $sections = [
                ['id' => 'acceptance',    'title' => __('landing.terms_acceptance_title'), 'content' => $terms->acceptance],
                ['id' => 'use-license',   'title' => __('landing.terms_license_title'),    'content' => $terms->use_license],
                ['id' => 'accounts',      'title' => __('landing.terms_accounts_title'),   'content' => $terms->user_accounts],
                ['id' => 'payments',      'title' => __('landing.terms_payments_title'),   'content' => $terms->payments],
                ['id' => 'prohibited',    'title' => __('landing.terms_prohibited_title'), 'content' => $terms->prohibited],
                ['id' => 'ip',            'title' => __('landing.terms_ip_title'),         'content' => $terms->intellectual_property],
                ['id' => 'liability',     'title' => __('landing.terms_liability_title'),  'content' => $terms->liability],
                ['id' => 'governing-law', 'title' => __('landing.terms_law_title'),        'content' => $terms->governing_law],
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
