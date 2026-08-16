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
    @if($seo)
        <title>{{ $seo->meta_title ?? $appName }}</title>
        <meta name="description" content="{{ $seo->meta_description ?? '' }}">
        @if($seo->meta_keywords)
            <meta name="keywords" content="{{ $seo->meta_keywords }}">
        @endif
        <meta property="og:title" content="{{ $seo->meta_title ?? $appName }}">
        <meta property="og:description" content="{{ $seo->meta_description ?? '' }}">
        @if($seo->og_image)
            <meta property="og:image" content="{{ asset($seo->og_image) }}">
        @endif
        @if($seo->favicon)
            <link rel="icon" href="{{ asset($seo->favicon) }}">
        @endif
    @else
        <title>{{ $appName }}</title>
        <link rel="icon" href="{{ asset('images/super/settings/favicon.ico') }}">
    @endif
    <link href="{{ asset('assets_super/css/inter.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/instrument-serif.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/sora.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/source-serif-4.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/landing.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/landing-two.css') }}" rel="stylesheet">
    @include('central.partials.landing-font')
</head>
<body class="landing-v2">
    {{-- ── Navbar ──────────────────────────────────────────────────── --}}
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
                @if($generalSettings->show_site_name ?? true)
                    <span class="brand-text">{{ $appName }}</span>
                @endif
            </a>

            {{-- Desktop nav links — floating pill --}}
            <div class="nav-pill" id="navPill">
                <ul class="navbar-nav">
                    @if(!empty($features['is_active']))
                        <li class="nav-item"><a class="nav-link" href="#features">{{ __('landing.features') }}</a></li>
                    @endif
                    @if(!empty($pricing['is_active']))
                        <li class="nav-item"><a class="nav-link" href="#pricing">{{ __('landing.pricing') }}</a></li>
                    @endif
                    @if(!empty($howItWorks['is_active']))
                        <li class="nav-item"><a class="nav-link" href="#how-it-works">{{ __('landing.how_it_works') }}</a></li>
                    @endif
                    @if(!empty($testimonials) && $testimonials->isNotEmpty())
                        <li class="nav-item"><a class="nav-link" href="#testimonials">{{ __('landing.testimonials') }}</a></li>
                    @endif
                    @if(!empty($faqs) && $faqs->isNotEmpty())
                        <li class="nav-item"><a class="nav-link" href="#faq">{{ __('landing.faq') }}</a></li>
                    @endif
                </ul>
                <span class="nav-indicator" id="navIndicator"></span>
            </div>

            <div class="navbar-actions">
                @if(isset($languages) && $languages->count() > 1)
                <div class="lang-switcher" id="langSwitcher">
                    <button type="button" class="lang-btn" id="langSwitcherBtn">
                        <i class="bi bi-globe2"></i>
                        {{ strtoupper($currentLocale ?? app()->getLocale()) }}
                        <i class="bi bi-chevron-down chevron-xs"></i>
                    </button>
                    <div class="lang-dropdown">
                        @foreach($languages as $lang)
                        <form method="POST" action="{{ route('central.locale', $lang->locale) }}">
                            @csrf
                            <button type="submit" class="{{ ($currentLocale ?? app()->getLocale()) === $lang->locale ? 'active' : '' }}">
                                @if($lang->flag)
                                    <img src="{{ asset('flags/' . $lang->flag) }}" alt="" class="flag-icon">
                                @endif
                                {{ $lang->name }}
                            </button>
                        </form>
                        @endforeach
                    </div>
                </div>
                @endif
                <a class="nav-btn nav-btn-ghost" href="{{ route('central.login') }}">
                    Already a member? Log In
                </a>
                <a class="nav-btn nav-btn-primary" href="{{ route('central.register') }}">{{ __('landing.sign_up_free') }} <i class="bi bi-arrow-right"></i></a>
            </div>

            <button class="navbar-toggler" type="button" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon">
                    <span class="hamburger-line"></span>
                </span>
            </button>

            {{-- Mobile fullscreen menu --}}
            <div class="navbar-collapse" id="navbarMain">
                <ul class="navbar-nav">
                    @if(!empty($features['is_active']))
                        <li class="nav-item"><a class="nav-link" href="#features"><i class="bi bi-grid-3x3-gap"></i> {{ __('landing.features') }}</a></li>
                    @endif
                    @if(!empty($pricing['is_active']))
                        <li class="nav-item"><a class="nav-link" href="#pricing"><i class="bi bi-currency-dollar"></i> {{ __('landing.pricing') }}</a></li>
                    @endif
                    @if(!empty($howItWorks['is_active']))
                        <li class="nav-item"><a class="nav-link" href="#how-it-works"><i class="bi bi-diagram-3"></i> {{ __('landing.how_it_works') }}</a></li>
                    @endif
                    @if(!empty($testimonials) && $testimonials->isNotEmpty())
                        <li class="nav-item"><a class="nav-link" href="#testimonials"><i class="bi bi-chat-quote"></i> {{ __('landing.testimonials') }}</a></li>
                    @endif
                    @if(!empty($faqs) && $faqs->isNotEmpty())
                        <li class="nav-item"><a class="nav-link" href="#faq"><i class="bi bi-question-circle"></i> {{ __('landing.faq') }}</a></li>
                    @endif
                </ul>
                <div class="mobile-nav-actions">
                    @if(isset($languages) && $languages->count() > 1)
                    <div class="lang-switcher" id="langSwitcherMobile">
                        <button type="button" class="lang-btn" id="langSwitcherMobileBtn">
                            <i class="bi bi-globe2"></i>
                            {{ strtoupper($currentLocale ?? app()->getLocale()) }}
                            <i class="bi bi-chevron-down chevron-xs"></i>
                        </button>
                        <div class="lang-dropdown">
                            @foreach($languages as $lang)
                            <form method="POST" action="{{ route('central.locale', $lang->locale) }}">
                                @csrf
                                <button type="submit" class="{{ ($currentLocale ?? app()->getLocale()) === $lang->locale ? 'active' : '' }}">
                                    @if($lang->flag)
                                        <img src="{{ asset('flags/' . $lang->flag) }}" alt="" class="flag-icon">
                                    @endif
                                    {{ $lang->name }}
                                </button>
                            </form>
                            @endforeach
                        </div>
                    </div>
                    @endif
                    @if($footer->show_admin_login ?? false)
                        <a class="btn-cta btn-cta-outline" href="{{ route('central.login') }}">{{ __('landing.admin_login') }}</a>
                    @endif
                    <a class="btn-cta btn-cta-accent" href="{{ route('central.register') }}">{{ __('landing.sign_up_free') }} <i class="bi bi-arrow-right"></i></a>
                </div>
            </div>
        </div>
    </nav>

    <main>
        {{-- ── Hero ────────────────────────────────────────────────── --}}
        @if($hero)
        <section id="hero" class="hero hero-clean @if($hero->background_image) hero-clean--photo bg-cover-center @endif" @if($hero->background_image) style="background-image: url('{{ asset($hero->background_image) }}')" @endif>
            <div class="hero-clean__bg" aria-hidden="true"></div>
            <div class="hero-clean__grid" aria-hidden="true"></div>
            <div class="hero-clean__wave" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 100" preserveAspectRatio="none">
                    <path fill="url(#heroWaveGrad)" d="M0,32 C240,92 480,8 720,44 S1200,4 1440,52 L1440,100 L0,100 Z"/>
                    <defs>
                        <linearGradient id="heroWaveGrad" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#0d9488" stop-opacity="0.06"/>
                            <stop offset="100%" stop-color="#0d9488" stop-opacity="0.02"/>
                        </linearGradient>
                    </defs>
                </svg>
            </div>
            <div class="container position-relative hero-clean__inner">
                <div class="row align-items-center g-4 g-lg-5 {{ $hero->hero_image ? '' : 'justify-content-center text-center' }}">
                    <div class="col-lg-6 {{ $hero->hero_image ? '' : 'col-xl-7' }}">
                        <div class="hero-copy">
                            @if($hero->subtitle)
                                <p class="hero-eyebrow">
                                    <span class="hero-eyebrow__rule" aria-hidden="true"></span>
                                    <span class="hero-eyebrow__label">{{ $hero->subtitle }}</span>
                                </p>
                            @endif
                            <h1 class="hero-title">{!! $hero->title !!}</h1>
                            @if($hero->description)
                                <p class="hero-lead">{{ $hero->description }}</p>
                            @endif
                            <div class="hero-actions">
                                <div class="hero-actions__cluster">
                                    @if($hero->primary_button_text)
                                        <a href="{{ $hero->primary_button_url ?? route('central.register') }}" class="btn-cta btn-cta-lg btn-cta-accent hero-btn-primary">
                                            {{ $hero->primary_button_text }} <i class="bi bi-arrow-right" aria-hidden="true"></i>
                                        </a>
                                    @endif
                                    @if($hero->secondary_button_text)
                                        <a href="{{ $hero->secondary_button_url ?? route('central.register') }}" class="btn-hero-secondary hero-btn-secondary">
                                            {{ $hero->secondary_button_text }}
                                        </a>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                    @if($hero->hero_image)
                    <div class="col-lg-6">
                        <div class="hero-visual" role="presentation">
                            <div class="hero-visual__glow" aria-hidden="true"></div>
                            <div class="hero-visual__frame">
                                <img src="{{ asset($hero->hero_image) }}" alt="{{ strip_tags($hero->title) }}" decoding="async">
                            </div>
                        </div>
                    </div>
                    @endif
                </div>
            </div>
            @if(session('success') && session('tenant_url'))
                <div class="container mt-4">
                    <div class="alert alert-custom text-center">
                        {{ session('message') }}<br>
                        <a href="{{ session('tenant_url') }}">{{ session('tenant_url') }}</a>
                    </div>
                </div>
            @endif
        </section>
        @endif

        {{-- ── Trust Bar ──────────────────────────────────────────── --}}
        @if(!empty($stats) && $stats->isNotEmpty())
        <section class="trust-bar" aria-label="{{ __('landing.trust_bar_aria') }}">
            <div class="trust-bar__accent" aria-hidden="true"></div>
            <div class="container trust-bar__container">
                <p class="trust-bar__eyebrow">
                    <span class="trust-bar__eyebrow-rule" aria-hidden="true"></span>
                    <span class="trust-bar__eyebrow-text">{{ __('landing.trust_bar_eyebrow') }}</span>
                </p>
                <div class="trust-stats" role="list">
                    @foreach($stats as $i => $stat)
                    <div class="trust-stat fade-up stagger-{{ ($i % 4) + 1 }}" role="listitem">
                        <div class="trust-stat__value">{{ $stat->value }}</div>
                        <div class="trust-stat__label">{{ $stat->label }}</div>
                    </div>
                    @endforeach
                </div>
            </div>
        </section>
        @endif

        {{-- ── Features ────────────────────────────────────────────── --}}
        @if(!empty($features['is_active']) && $features['items']->isNotEmpty())
        <section id="features" class="features-section">
            <div class="features-section__accent" aria-hidden="true"></div>
            <div class="container">
                <header class="features-section__head text-center">
                    <p class="features-section__eyebrow">
                        <span class="features-section__eyebrow-rule" aria-hidden="true"></span>
                        <span class="features-section__eyebrow-text">{{ __('landing.features') }}</span>
                    </p>
                    @if($features['section'])
                        <h2 class="features-section__title fade-up">{{ $features['section']->section_title }}</h2>
                        <p class="features-section__lead fade-up">{{ $features['section']->section_subtitle }}</p>
                    @endif
                </header>
                <div class="row g-4 features-section__grid">
                    @foreach($features['items'] as $i => $feature)
                    <div class="col-md-6 col-lg-{{ count($features['items']) <= 3 ? '4' : '3' }}">
                        <article class="feature-card fade-up stagger-{{ ($i % 4) + 1 }}">
                            @if($feature->image)
                                <div class="feature-card__icon">
                                    <img src="{{ asset($feature->image) }}" alt="" class="landing-feature-img" decoding="async">
                                </div>
                            @elseif($feature->icon)
                                <div class="feature-card__icon" aria-hidden="true"><i class="{{ $feature->icon }}"></i></div>
                            @else
                                <div class="feature-card__icon" aria-hidden="true"><i class="bi bi-star"></i></div>
                            @endif
                            <h3 class="feature-card__title">{{ $feature->title }}</h3>
                            @if($feature->description)
                                <p class="feature-card__desc">{{ $feature->description }}</p>
                            @endif
                        </article>
                    </div>
                    @endforeach
                </div>
            </div>
        </section>
        @endif

        {{-- ── Pricing (Apple-minimal) ─────────────────────────────── --}}
        @if(!empty($pricing['is_active']))
        <section id="pricing" class="pricing-section">
            <div class="pricing-section__canvas" aria-hidden="true"></div>
            <div class="container">
                <header class="pricing-section__head text-center">
                    <p class="pricing-section__eyebrow">
                        <span class="pricing-section__eyebrow-rule" aria-hidden="true"></span>
                        <span class="pricing-section__eyebrow-text">{{ __('landing.pricing') }}</span>
                    </p>
                    @if($pricing['settings'])
                        <h2 class="pricing-section__title fade-up">{{ $pricing['settings']->section_title }}</h2>
                        <p class="pricing-section__lead fade-up">{{ $pricing['settings']->section_subtitle }}</p>
                    @endif
                </header>

                @if(!empty($pricing['plans']))
                    <div class="row g-4 g-lg-5 justify-content-center pricing-section__grid">
                        @foreach($pricing['plans'] as $i => $plan)
                        <div class="col-lg-4 col-md-6 d-flex fade-up stagger-{{ ($i % 3) + 1 }}">
                            <article class="pricing-tier {{ $i === 1 ? 'pricing-tier--featured' : '' }}">
                                @if($i === 1)
                                    <p class="pricing-tier__tag">{{ __('landing.most_popular') }}</p>
                                @endif
                                <h3 class="pricing-tier__name">{{ $plan->name }}</h3>
                                <p class="pricing-tier__hint">
                                    @if($plan->price == 0)
                                        {{ __('landing.perfect_to_start') ?? 'Perfect to get started' }}
                                    @else
                                        {{ __('landing.for_growing_teams') ?? 'For growing teams' }}
                                    @endif
                                </p>
                                <div class="pricing-tier__price-block">
                                    @if($plan->price == 0 && $plan->yearly_price == 0)
                                        <span class="pricing-tier__amount pricing-tier__amount--free">{{ __('landing.free') }}</span>
                                    @else
                                        <span class="pricing-tier__amount">
                                            <span class="pricing-tier__currency">{{ $currencySymbol }}</span>{{ $plan->price == floor($plan->price) ? number_format($plan->price, 0) : number_format($plan->price, 2) }}
                                        </span>
                                        <span class="pricing-tier__period">/ {{ __('landing.mo') }}</span>
                                    @endif
                                </div>
                                @if($plan->yearly_price > 0)
                                    <p class="pricing-tier__yearly">
                                        {{ __('landing.or') }} {{ $currencySymbol }}{{ $plan->yearly_price == floor($plan->yearly_price) ? number_format($plan->yearly_price, 0) : number_format($plan->yearly_price, 2) }} / {{ __('landing.yr') }}
                                        @if($plan->getYearlySavingsPercent() > 0)
                                            <span class="pricing-tier__save">{{ __('landing.save') }} {{ $plan->getYearlySavingsPercent() }}%</span>
                                        @endif
                                    </p>
                                @endif
                                @php
                                    $limits = $plan->limits ?? [];
                                    $planFeatures = $plan->features ?? [];
                                @endphp
                                <ul class="pricing-tier__features">
                                    @foreach($limits as $key => $value)
                                        @php $meta = \App\Models\Central\Plan::AVAILABLE_LIMITS[$key] ?? null; @endphp
                                        @if($meta)
                                            <li>
                                                <i class="bi bi-check-lg pricing-tier__check" aria-hidden="true"></i>
                                                <span>{{ $value == -1 ? __('landing.unlimited') : $value }} {{ $meta['label'] }}</span>
                                            </li>
                                        @endif
                                    @endforeach
                                    @foreach($planFeatures as $fKey)
                                        @php $fMeta = \App\Models\Central\Plan::AVAILABLE_FEATURES[$fKey] ?? null; @endphp
                                        @if($fMeta)
                                            <li>
                                                <i class="bi bi-check-lg pricing-tier__check" aria-hidden="true"></i>
                                                <span>{{ $fMeta['label'] }}</span>
                                            </li>
                                        @endif
                                    @endforeach
                                </ul>
                                <a href="{{ route('central.register', ['plan' => $plan->id]) }}" class="pricing-tier__cta">
                                    {{ $plan->isFree() ? __('landing.get_started') : ($plan->hasTrial() ? __('landing.start_free_trial') : __('landing.choose_plan')) }}
                                </a>
                            </article>
                        </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>
        @endif

        {{-- ── How It Works ────────────────────────────────────────── --}}
        @if(!empty($howItWorks['is_active']))
        <section class="hiw-section" id="how-it-works">
            <div class="hiw-section__accent" aria-hidden="true"></div>
            <div class="container">
                <header class="hiw-section__head text-center">
                    <p class="hiw-section__eyebrow">
                        <span class="hiw-section__eyebrow-rule" aria-hidden="true"></span>
                        <span class="hiw-section__eyebrow-text">{{ $howItWorks['section']->section_label ?? __('landing.how_it_works') }}</span>
                    </p>
                    <h2 class="hiw-section__title fade-up">{{ $howItWorks['section']->section_title ?? __('landing.how_it_works_title') }}</h2>
                    <p class="hiw-section__lead fade-up">{{ $howItWorks['section']->section_subtitle ?? __('landing.how_it_works_subtitle') }}</p>
                </header>
                <div class="hiw-section__grid">
                    @forelse($howItWorks['steps'] as $i => $step)
                    <article class="hiw-card fade-up stagger-{{ ($i % 3) + 1 }}">
                        <span class="hiw-card__step">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>
                        <div class="hiw-card__icon" aria-hidden="true">
                            <i class="{{ $step->icon ?? 'bi bi-check-circle' }}"></i>
                        </div>
                        <h3 class="hiw-card__title">{{ $step->title }}</h3>
                        <p class="hiw-card__desc">{{ $step->description }}</p>
                    </article>
                    @empty
                    {{-- Fallback to translation strings if no steps in DB --}}
                    <article class="hiw-card fade-up stagger-1">
                        <span class="hiw-card__step">01</span>
                        <div class="hiw-card__icon" aria-hidden="true"><i class="bi bi-arrow-repeat"></i></div>
                        <h3 class="hiw-card__title">{{ __('landing.hiw_features_title') }}</h3>
                        <p class="hiw-card__desc">{{ __('landing.hiw_features_desc') }}</p>
                    </article>
                    <article class="hiw-card fade-up stagger-2">
                        <span class="hiw-card__step">02</span>
                        <div class="hiw-card__icon" aria-hidden="true"><i class="bi bi-credit-card"></i></div>
                        <h3 class="hiw-card__title">{{ __('landing.hiw_pricing_title') }}</h3>
                        <p class="hiw-card__desc">{{ __('landing.hiw_pricing_desc') }}</p>
                    </article>
                    <article class="hiw-card fade-up stagger-3">
                        <span class="hiw-card__step">03</span>
                        <div class="hiw-card__icon" aria-hidden="true"><i class="bi bi-shield-check"></i></div>
                        <h3 class="hiw-card__title">{{ __('landing.hiw_delete_title') }}</h3>
                        <p class="hiw-card__desc">{{ __('landing.hiw_delete_desc') }}</p>
                    </article>
                    <article class="hiw-card fade-up stagger-1">
                        <span class="hiw-card__step">04</span>
                        <div class="hiw-card__icon" aria-hidden="true"><i class="bi bi-database-lock"></i></div>
                        <h3 class="hiw-card__title">{{ __('landing.hiw_data_title') }}</h3>
                        <p class="hiw-card__desc">{{ __('landing.hiw_data_desc') }}</p>
                    </article>
                    <article class="hiw-card fade-up stagger-2">
                        <span class="hiw-card__step">05</span>
                        <div class="hiw-card__icon" aria-hidden="true"><i class="bi bi-clock-history"></i></div>
                        <h3 class="hiw-card__title">{{ __('landing.hiw_trial_title') }}</h3>
                        <p class="hiw-card__desc">{{ __('landing.hiw_trial_desc') }}</p>
                    </article>
                    <article class="hiw-card fade-up stagger-3">
                        <span class="hiw-card__step">06</span>
                        <div class="hiw-card__icon" aria-hidden="true"><i class="bi bi-arrow-up-circle"></i></div>
                        <h3 class="hiw-card__title">{{ __('landing.hiw_upgrade_title') }}</h3>
                        <p class="hiw-card__desc">{{ __('landing.hiw_upgrade_desc') }}</p>
                    </article>
                    @endforelse
                </div>
            </div>
        </section>
        @endif

        {{-- ── Testimonials ────────────────────────────────────────── --}}
        @if(!empty($testimonials) && $testimonials->isNotEmpty())
        <section id="testimonials" class="testimonials-section">
            <div class="testimonials-section__accent" aria-hidden="true"></div>
            <div class="container">
                <header class="testimonials-section__head text-center">
                    <p class="testimonials-section__eyebrow">
                        <span class="testimonials-section__eyebrow-rule" aria-hidden="true"></span>
                        <span class="testimonials-section__eyebrow-text">{{ __('landing.testimonials') }}</span>
                    </p>
                    <h2 class="testimonials-section__title fade-up">{{ __('landing.loved_by_businesses') }}</h2>
                    <p class="testimonials-section__lead fade-up">{{ __('landing.testimonials_subtitle') }}</p>
                </header>
                <div class="row g-4 testimonials-section__grid">
                    @foreach($testimonials as $i => $testimonial)
                    <div class="col-lg-4 col-md-6 d-flex fade-up stagger-{{ ($i % 3) + 1 }}">
                        <article class="testimonial-card">
                            @if($testimonial->rating)
                            <div class="testimonial-card__stars" aria-label="{{ $testimonial->rating }} / 5">
                                @for($s = 1; $s <= 5; $s++)
                                    <i class="bi bi-star{{ $s <= $testimonial->rating ? '-fill' : '' }}" aria-hidden="true"></i>
                                @endfor
                            </div>
                            @endif
                            <blockquote class="testimonial-card__quote">
                                <p>“{{ $testimonial->review }}”</p>
                            </blockquote>
                            <footer class="testimonial-card__author">
                                <div class="testimonial-card__avatar">
                                    @if($testimonial->avatar)
                                        <img src="{{ asset($testimonial->avatar) }}" alt="" decoding="async">
                                    @else
                                        <span aria-hidden="true">{{ strtoupper(mb_substr($testimonial->client_name, 0, 1)) }}</span>
                                    @endif
                                </div>
                                <div class="testimonial-card__meta">
                                    <p class="testimonial-card__name">{{ $testimonial->client_name }}</p>
                                    @if($testimonial->company_name)
                                        <span class="testimonial-card__role">{{ $testimonial->company_name }}</span>
                                    @endif
                                </div>
                            </footer>
                        </article>
                    </div>
                    @endforeach
                </div>
            </div>
        </section>
        @endif

        {{-- ── FAQ ─────────────────────────────────────────────────── --}}
        @if(!empty($faqs) && $faqs->isNotEmpty())
        <section id="faq" class="faq-section">
            <div class="faq-section__accent" aria-hidden="true"></div>
            <div class="container">
                <header class="faq-section__head text-center">
                    <p class="faq-section__eyebrow">
                        <span class="faq-section__eyebrow-rule" aria-hidden="true"></span>
                        <span class="faq-section__eyebrow-text">{{ __('landing.faq') ?? 'FAQ' }}</span>
                    </p>
                    <h2 class="faq-section__title fade-up">{{ __('landing.faq_title') }}</h2>
                    <p class="faq-section__lead fade-up">{{ __('landing.faq_subtitle') }}</p>
                </header>
                <div class="faq-section__wrap">
                    <div class="faq-section__panel">
                        @foreach($faqs as $i => $faq)
                        <details class="faq-item fade-up stagger-{{ ($i % 4) + 1 }}">
                            <summary>
                                <span class="faq-item__label">{{ $faq->question }}</span>
                                <i class="bi bi-chevron-down faq-item__chevron" aria-hidden="true"></i>
                            </summary>
                            <div class="faq-item__answer">{!! nl2br(e($faq->answer)) !!}</div>
                        </details>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>
        @endif

        {{-- ── CTA ─────────────────────────────────────────────────── --}}
        @if($cta)
        <section class="cta-section @if($cta->background_image) cta-section--photo bg-cover-center @endif" @if($cta->background_image) style="background-image: url('{{ asset($cta->background_image) }}')" @endif>
            <div class="cta-section__scrim" aria-hidden="true"></div>
            <div class="cta-section__glow" aria-hidden="true"></div>
            <div class="container position-relative">
                <div class="cta-section__content text-center mx-auto">
                    <h2 class="cta-section__title fade-up">{{ $cta->title }}</h2>
                    @if($cta->subtitle)
                        <p class="cta-section__lead fade-up">{{ $cta->subtitle }}</p>
                    @endif
                    @if($cta->button_text)
                        <a href="{{ $cta->button_url ?? route('central.register') }}" class="cta-section__btn fade-up">
                            {{ $cta->button_text }}
                            <i class="bi bi-arrow-right" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>
            </div>
        </section>
        @endif
    </main>

    {{-- ── Footer ──────────────────────────────────────────────────── --}}
    <footer class="landing-footer">
        <div class="landing-footer__accent" aria-hidden="true"></div>
        <div class="container position-relative">
            <div class="row landing-footer__grid g-4 g-lg-5">
                <div class="col-lg-4 mb-2 mb-lg-0">
                    @if($generalSettings->show_site_name ?? true)
                        <a class="landing-footer__brand" href="{{ route('central.welcome') }}">{{ $appName }}</a>
                    @endif
                    @if($footer && $footer->footer_about)
                        <p class="landing-footer__about">{{ $footer->footer_about }}</p>
                    @endif
                    @if($footer)
                    <div class="landing-footer__social">
                        @foreach([
                            'facebook' => ['icon' => 'bi-facebook', 'label' => 'Facebook'],
                            'twitter' => ['icon' => 'bi-twitter-x', 'label' => 'X'],
                            'linkedin' => ['icon' => 'bi-linkedin', 'label' => 'LinkedIn'],
                            'instagram' => ['icon' => 'bi-instagram', 'label' => 'Instagram'],
                            'youtube' => ['icon' => 'bi-youtube', 'label' => 'YouTube'],
                        ] as $social => $meta)
                            @if($footer->$social)
                                <a href="{{ $footer->$social }}" class="landing-footer__social-link" target="_blank" rel="noopener noreferrer" aria-label="{{ $meta['label'] }}">
                                    <i class="bi {{ $meta['icon'] }}" aria-hidden="true"></i>
                                </a>
                            @endif
                        @endforeach
                    </div>
                    @endif
                </div>
                <div class="col-lg-4 col-md-6">
                    <p class="landing-footer__heading">{{ __('landing.quick_links') }}</p>
                    <nav class="landing-footer__nav" aria-label="{{ __('landing.quick_links') }}">
                        <a href="{{ route('central.welcome') }}">{{ __('landing.home') }}</a>
                        <a href="#features">{{ __('landing.features') }}</a>
                        <a href="#pricing">{{ __('landing.pricing') }}</a>
                        <a href="{{ route('central.register') }}">{{ __('landing.sign_up') }}</a>
                        @if($footer->show_admin_login ?? false)
                        <a href="{{ route('central.login') }}">{{ __('landing.admin_login') }}</a>
                        @endif
                        <a href="{{ route('central.privacy-policy') }}">{{ __('landing.privacy_policy') }}</a>
                        <a href="{{ route('central.terms-conditions') }}">{{ __('landing.terms_and_conditions') }}</a>
                        <a href="#" id="cookiePreferencesLink">{{ __('landing.cookie_preferences_link') }}</a>
                    </nav>
                </div>
                <div class="col-lg-4 col-md-6">
                    @if($footer && ($footer->contact_email || $footer->contact_phone || $footer->address))
                    <p class="landing-footer__heading">{{ __('landing.contact') }}</p>
                    <div class="landing-footer__contact">
                        @if($footer->contact_email)
                            <a class="landing-footer__contact-item" href="mailto:{{ $footer->contact_email }}">
                                <i class="bi bi-envelope" aria-hidden="true"></i>
                                <span>{{ $footer->contact_email }}</span>
                            </a>
                        @endif
                        @if($footer->contact_phone)
                            <a class="landing-footer__contact-item" href="tel:{{ preg_replace('/[^0-9+]/', '', $footer->contact_phone) }}">
                                <i class="bi bi-telephone" aria-hidden="true"></i>
                                <span>{{ $footer->contact_phone }}</span>
                            </a>
                        @endif
                        @if($footer->address)
                            <p class="landing-footer__contact-item landing-footer__contact-item--static">
                                <i class="bi bi-geo-alt" aria-hidden="true"></i>
                                <span>{{ $footer->address }}</span>
                            </p>
                        @endif
                    </div>
                    @endif
                </div>
            </div>
            <div class="landing-footer__bottom">
                <p class="landing-footer__legal">{{ $footer->copyright_text ?? '© ' . date('Y') . ' ' . $appName . '. All rights reserved.' }}</p>
            </div>
        </div>
    </footer>

    <script src="{{ asset('assets_super/js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('assets_super/js/landing-two.js') }}"></script>

    {{-- ── Cookie Consent Banner ──────────────────────────────────────── --}}
    <div class="cookie-consent" id="cookieConsent">
        <div class="cookie-consent-header">
            <i class="bi bi-shield-lock"></i>
            <h4>{{ __('landing.cookie_banner_title') }}</h4>
        </div>
        <p class="cookie-consent-text">
            {{ __('landing.cookie_banner_text') }}
            <a href="{{ route('central.privacy-policy') }}#cookies">{{ __('landing.privacy_policy') }}</a>
        </p>
        <div class="cookie-consent-actions">
            <button class="cb-btn cb-btn-accept" id="cookieAcceptBtn">{{ __('landing.cookie_accept_all') }}</button>
            <button class="cb-btn cb-btn-reject" id="cookieRejectBtn">{{ __('landing.cookie_reject_all') }}</button>
            <button class="cb-btn cb-btn-customize" id="cookieCustomizeBtn">{{ __('landing.cookie_customize') }}</button>
        </div>
        <div class="cookie-customize-panel" id="cookieCustomize">
            <div class="cookie-option">
                <div class="cookie-option-info">
                    <h5>{{ __('landing.cookie_necessary') }}</h5>
                    <p>{{ __('landing.cookie_necessary_desc') }}</p>
                </div>
                <label class="cookie-toggle">
                    <input type="checkbox" checked disabled>
                    <span class="slider"></span>
                </label>
            </div>
            <div class="cookie-option">
                <div class="cookie-option-info">
                    <h5>{{ __('landing.cookie_analytics') }}</h5>
                    <p>{{ __('landing.cookie_analytics_desc') }}</p>
                </div>
                <label class="cookie-toggle">
                    <input type="checkbox" id="cookieAnalytics">
                    <span class="slider"></span>
                </label>
            </div>
            <div class="cookie-option">
                <div class="cookie-option-info">
                    <h5>{{ __('landing.cookie_marketing') }}</h5>
                    <p>{{ __('landing.cookie_marketing_desc') }}</p>
                </div>
                <label class="cookie-toggle">
                    <input type="checkbox" id="cookieMarketing">
                    <span class="slider"></span>
                </label>
            </div>
            <div class="cookie-save-row">
                <button class="cb-btn" id="cookieSaveBtn">{{ __('landing.cookie_save_preferences') }}</button>
            </div>
        </div>
    </div>

</body>
</html>
