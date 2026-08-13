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
    <meta name="color-scheme" content="light">
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
    <link href="{{ asset('assets_super/css/dm-sans.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/jetbrains-mono.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/landing-five.css') }}" rel="stylesheet">
    @include('central.partials.landing-font')
</head>
<body class="l5-body">
    <header class="l5-header" id="l5Header">
        <div class="l5-header__dock">
            <div class="l5-wrap">
                <div class="l5-header__surface">
        <div class="l5-header__bar">
            <a class="l5-brand" href="{{ route('central.welcome') }}">
                <span class="l5-brand__mark">
                    @if($logoUrl)
                        <img src="{{ $logoUrl }}" alt="">
                    @else
                        {{ strtoupper(substr($appName, 0, 1)) }}
                    @endif
                </span>
                @if($generalSettings->show_site_name ?? true)
                    <span>{{ $appName }}</span>
                @endif
            </a>

            <nav class="l5-nav-desktop" aria-label="{{ __('landing.quick_links') }}">
                @if(!empty($features['is_active']))
                    <a href="#features">{{ __('landing.features') }}</a>
                @endif
                @if(!empty($howItWorks['is_active']))
                    <a href="#journey">{{ __('landing.how_it_works') }}</a>
                @endif
                @if(!empty($pricing['is_active']))
                    <a href="#pricing">{{ __('landing.pricing') }}</a>
                @endif
                @if(!empty($testimonials) && $testimonials->isNotEmpty())
                    <a href="#voices">{{ __('landing.testimonials') }}</a>
                @endif
                @if(!empty($faqs) && $faqs->isNotEmpty())
                    <a href="#faq">{{ __('landing.faq') }}</a>
                @endif
            </nav>

            <div class="l5-header__actions">
                @if(isset($languages) && $languages->count() > 1)
                <div class="l5-lang" id="l5Lang">
                    <button type="button" class="l5-lang__btn" id="l5LangBtn" aria-expanded="false" aria-haspopup="true">
                        <i class="bi bi-globe2" aria-hidden="true"></i>
                        {{ strtoupper($currentLocale ?? app()->getLocale()) }}
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </button>
                    <div class="l5-lang__dropdown" role="menu">
                        @foreach($languages as $lang)
                        <form method="POST" action="{{ route('central.locale', $lang->locale) }}">
                            @csrf
                            <button type="submit" class="{{ ($currentLocale ?? app()->getLocale()) === $lang->locale ? 'active' : '' }}" role="menuitem">
                                @if($lang->flag)
                                    <img src="{{ asset('flags/' . $lang->flag) }}" alt="" class="l5-flag">
                                @endif
                                {{ $lang->name }}
                            </button>
                        </form>
                        @endforeach
                    </div>
                </div>
                @endif
                @if($footer && ($footer->show_admin_login ?? false))
                    <a class="l5-btn l5-btn--ghost" data-l5-desktop href="{{ route('central.login') }}">{{ __('landing.admin_login') }}</a>
                @endif
                <a class="l5-btn l5-btn--primary" href="{{ route('central.register') }}">{{ __('landing.sign_up_free') }}</a>
                <button type="button" class="l5-menu-btn" id="l5OpenMenu" aria-expanded="false" aria-controls="l5Drawer" aria-label="Menu">
                    <i class="bi bi-list" aria-hidden="true"></i>
                </button>
            </div>
                </div>
            </div>
        </div>
    </header>

    <div class="l5-drawer" id="l5Drawer" hidden aria-hidden="true">
        <div class="l5-drawer__panel" role="dialog" aria-modal="true" aria-label="Menu">
            <div class="l5-drawer__close">
                <button type="button" class="l5-menu-btn" id="l5CloseMenu" aria-label="Close">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
            </div>
            <nav class="l5-drawer__nav" aria-label="{{ __('landing.quick_links') }}">
                @if(!empty($features['is_active']))
                    <a href="#features">{{ __('landing.features') }}</a>
                @endif
                @if(!empty($howItWorks['is_active']))
                    <a href="#journey">{{ __('landing.how_it_works') }}</a>
                @endif
                @if(!empty($pricing['is_active']))
                    <a href="#pricing">{{ __('landing.pricing') }}</a>
                @endif
                @if(!empty($testimonials) && $testimonials->isNotEmpty())
                    <a href="#voices">{{ __('landing.testimonials') }}</a>
                @endif
                @if(!empty($faqs) && $faqs->isNotEmpty())
                    <a href="#faq">{{ __('landing.faq') }}</a>
                @endif
            </nav>
            <div class="l5-drawer__actions">
                @if($footer && ($footer->show_admin_login ?? false))
                    <a class="l5-btn l5-btn--outline" href="{{ route('central.login') }}">{{ __('landing.admin_login') }}</a>
                @endif
                <a class="l5-btn l5-btn--primary" href="{{ route('central.register') }}">{{ __('landing.sign_up_free') }}</a>
            </div>
        </div>
    </div>

    <main id="main">
        <section class="l5-hero l5-hero--lifecycle" id="top" aria-labelledby="l5-hero-title">
            <div class="l5-hero__glow" aria-hidden="true"></div>
            <div class="l5-hero__pipeline" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 820 340" fill="none" focusable="false">
                    <defs>
                        <linearGradient id="l5PipeFlow" x1="0" y1="0" x2="820" y2="0">
                            <stop offset="0%" stop-color="#1273eb"/>
                            <stop offset="45%" stop-color="#0891b2"/>
                            <stop offset="100%" stop-color="#1273eb"/>
                        </linearGradient>
                        <linearGradient id="l5PipeSoft" x1="40" y1="120" x2="780" y2="200">
                            <stop offset="0%" stop-color="#1273eb" stop-opacity="0.35"/>
                            <stop offset="100%" stop-color="#06b6d4" stop-opacity="0.25"/>
                        </linearGradient>
                    </defs>
                    <path d="M 32 246 C 120 88 200 72 288 168 C 352 232 392 228 448 198 C 512 164 568 172 628 188 C 688 204 732 188 788 158" stroke="url(#l5PipeSoft)" stroke-width="22" stroke-linecap="round" stroke-linejoin="round" opacity="0.9"/>
                    <path d="M 32 246 C 120 88 200 72 288 168 C 352 232 392 228 448 198 C 512 164 568 172 628 188 C 688 204 732 188 788 158" stroke="url(#l5PipeFlow)" stroke-width="3.2" stroke-dasharray="11 13" stroke-linecap="round" stroke-linejoin="round" opacity="0.55"/>
                    <g stroke-width="2.5">
                        <circle cx="72" cy="228" r="21" fill="#fff" stroke="#1273eb"/>
                        <circle cx="72" cy="228" r="7" fill="#1273eb"/>
                        <circle cx="248" cy="118" r="21" fill="#fff" stroke="#0891b2"/>
                        <circle cx="248" cy="118" r="7" fill="#0891b2"/>
                        <circle cx="418" cy="206" r="21" fill="#fff" stroke="#1273eb"/>
                        <circle cx="418" cy="206" r="7" fill="#1273eb"/>
                        <circle cx="588" cy="178" r="21" fill="#fff" stroke="#0891b2"/>
                        <circle cx="588" cy="178" r="7" fill="#0891b2"/>
                        <circle cx="752" cy="162" r="21" fill="#fff" stroke="#ea580c"/>
                        <circle cx="752" cy="162" r="7" fill="#ea580c"/>
                    </g>
                </svg>
            </div>
            <div class="l5-hero__wave" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 100" preserveAspectRatio="none" class="l5-hero__wave-svg">
                    <path fill="url(#l5HeroWaveGrad)" d="M0,32 C240,92 480,8 720,44 S1200,4 1440,52 L1440,100 L0,100 Z"/>
                    <defs>
                        <linearGradient id="l5HeroWaveGrad" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#0c2744" stop-opacity="0.07"/>
                            <stop offset="100%" stop-color="#0c2744" stop-opacity="0.02"/>
                        </linearGradient>
                    </defs>
                </svg>
            </div>
            <div class="l5-wrap">
                <div class="l5-hero__shell">
                    <div class="l5-hero__intro">
                        @if($hero && $hero->subtitle)
                            <p class="l5-eyebrow">{{ $hero->subtitle }}</p>
                        @else
                            <p class="l5-eyebrow">{{ __('landing.trust_bar_eyebrow') }}</p>
                        @endif
                        <h1 class="l5-hero__title" id="l5-hero-title">
                            @if($hero)
                                {!! $hero->title !!}
                            @else
                                {{ $appName }}
                            @endif
                        </h1>
                        @if($hero && $hero->description)
                            <p class="l5-hero__sub">{{ $hero->description }}</p>
                        @elseif(!$hero)
                            <p class="l5-hero__sub">{{ __('landing.testimonials_subtitle') }}</p>
                        @endif
                        <div class="l5-hero__cta">
                            @if($hero && $hero->primary_button_text)
                                <a href="{{ $hero->primary_button_url ?? route('central.register') }}" class="l5-btn l5-btn--primary l5-btn--lg">
                                    {{ $hero->primary_button_text }} <i class="bi bi-arrow-right" aria-hidden="true"></i>
                                </a>
                            @else
                                <a href="{{ route('central.register') }}" class="l5-btn l5-btn--primary l5-btn--lg">
                                    {{ __('landing.sign_up_free') }} <i class="bi bi-arrow-right" aria-hidden="true"></i>
                                </a>
                            @endif
                            @if($hero && $hero->secondary_button_text)
                                <a href="{{ $hero->secondary_button_url ?? route('central.register') }}" class="l5-btn l5-btn--outline l5-btn--lg">{{ $hero->secondary_button_text }}</a>
                            @endif
                        </div>
                        @if(session('success') && session('tenant_url'))
                            <div class="l5-alert" role="status">
                                {{ session('message') }}<br>
                                <a href="{{ session('tenant_url') }}">{{ session('tenant_url') }}</a>
                            </div>
                        @endif
                    </div>
                </div>
                @if(isset($stats) && $stats->isNotEmpty())
                <aside class="l5-hero__strip" aria-label="{{ __('landing.trust_bar_aria') }}">
                    <ul class="l5-hero-metrics">
                        @foreach($stats as $stat)
                        <li class="l5-hero-metrics__item l5-reveal">
                            <span class="l5-hero-metrics__value">{{ $stat->value }}</span>
                            <span class="l5-hero-metrics__label">{{ $stat->label }}</span>
                        </li>
                        @endforeach
                    </ul>
                </aside>
                @endif
            </div>
        </section>

        @if(!empty($features['is_active']) && $features['items']->isNotEmpty())
        <section class="l5-section l5-solutions" id="features" aria-labelledby="l5-features-title">
            <div class="l5-wrap">
                <header class="l5-solutions__head l5-reveal">
                    <p class="l5-eyebrow">{{ __('landing.features') }}</p>
                    @if($features['section'])
                        <h2 class="l5-h2" id="l5-features-title">{{ $features['section']->section_title }}</h2>
                        <p class="l5-lead">{{ $features['section']->section_subtitle }}</p>
                    @else
                        <h2 class="l5-h2" id="l5-features-title">{{ __('landing.features') }}</h2>
                    @endif
                </header>
                <ol class="l5-sol-list">
                    @foreach($features['items'] as $i => $feature)
                    <li class="l5-sol-item l5-reveal {{ $i % 2 === 1 ? 'l5-sol-item--reverse' : '' }}">
                        <article class="l5-sol-card">
                            <div class="l5-sol-card__body">
                                <h3 class="l5-sol-card__title">{{ $feature->title }}</h3>
                                @if($feature->description)
                                    <p class="l5-sol-card__desc">{{ $feature->description }}</p>
                                @endif
                            </div>
                            <div class="l5-sol-card__media" aria-hidden="true">
                                @if($feature->image)
                                    <img src="{{ asset($feature->image) }}" alt="" decoding="async">
                                @elseif($feature->icon)
                                    <i class="{{ $feature->icon }}"></i>
                                @else
                                    <i class="bi bi-stars"></i>
                                @endif
                            </div>
                        </article>
                    </li>
                    @endforeach
                </ol>
            </div>
        </section>
        @endif

        @if(!empty($howItWorks['is_active']))
        <section class="l5-section l5-track" id="journey" aria-labelledby="l5-track-title">
            <div class="l5-wrap l5-track__inner">
                <header class="l5-track__head l5-reveal">
                    <p class="l5-eyebrow">{{ $howItWorks['section']->section_label ?? __('landing.how_it_works') }}</p>
                    <h2 class="l5-h2" id="l5-track-title">{{ $howItWorks['section']->section_title ?? __('landing.how_it_works_title') }}</h2>
                    <p class="l5-lead">{{ $howItWorks['section']->section_subtitle ?? __('landing.how_it_works_subtitle') }}</p>
                </header>
                <ol class="l5-track__list">
                    @forelse($howItWorks['steps'] as $i => $step)
                    <li class="l5-track__step l5-reveal">
                        <div class="l5-track__rail" aria-hidden="true"></div>
                        <div class="l5-track__body">
                            <div class="l5-track__row">
                                <span class="l5-track__index">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>
                                <h3 class="l5-track__title">{{ $step->title }}</h3>
                            </div>
                            <p class="l5-track__desc">{{ $step->description }}</p>
                        </div>
                    </li>
                    @empty
                    <li class="l5-track__step l5-reveal">
                        <div class="l5-track__rail" aria-hidden="true"></div>
                        <div class="l5-track__body">
                            <div class="l5-track__row">
                                <span class="l5-track__index">01</span>
                                <h3 class="l5-track__title">{{ __('landing.hiw_features_title') }}</h3>
                            </div>
                            <p class="l5-track__desc">{{ __('landing.hiw_features_desc') }}</p>
                        </div>
                    </li>
                    <li class="l5-track__step l5-reveal">
                        <div class="l5-track__rail" aria-hidden="true"></div>
                        <div class="l5-track__body">
                            <div class="l5-track__row">
                                <span class="l5-track__index">02</span>
                                <h3 class="l5-track__title">{{ __('landing.hiw_pricing_title') }}</h3>
                            </div>
                            <p class="l5-track__desc">{{ __('landing.hiw_pricing_desc') }}</p>
                        </div>
                    </li>
                    <li class="l5-track__step l5-reveal">
                        <div class="l5-track__rail" aria-hidden="true"></div>
                        <div class="l5-track__body">
                            <div class="l5-track__row">
                                <span class="l5-track__index">03</span>
                                <h3 class="l5-track__title">{{ __('landing.hiw_data_title') }}</h3>
                            </div>
                            <p class="l5-track__desc">{{ __('landing.hiw_data_desc') }}</p>
                        </div>
                    </li>
                    @endforelse
                </ol>
            </div>
        </section>
        @endif

        @if(!empty($testimonials) && $testimonials->isNotEmpty())
        <section class="l5-section l5-voices" id="voices" aria-labelledby="l5-voices-title">
            <div class="l5-wrap">
                <header class="l5-voices__head l5-reveal">
                    <p class="l5-eyebrow">{{ __('landing.testimonials') }}</p>
                    <h2 class="l5-h2" id="l5-voices-title">{{ __('landing.loved_by_businesses') }}</h2>
                    <p class="l5-lead">{{ __('landing.testimonials_subtitle') }}</p>
                </header>
                <ul class="l5-voices__list">
                    @foreach($testimonials as $t)
                    <li class="l5-voices__item">
                        <figure class="l5-voice l5-reveal">
                            <blockquote class="l5-voice__quote">
                                <p>“{{ $t->review }}”</p>
                            </blockquote>
                            <figcaption class="l5-voice__meta">
                                <span class="l5-voice__avatar">
                                    @if($t->avatar)
                                        <img src="{{ asset($t->avatar) }}" alt="" decoding="async">
                                    @else
                                        {{ strtoupper(mb_substr($t->client_name, 0, 1)) }}
                                    @endif
                                </span>
                                <span class="l5-voice__who">
                                    <cite class="l5-voice__name">{{ $t->client_name }}</cite>
                                    @if($t->company_name)
                                        <span class="l5-voice__role">{{ $t->company_name }}</span>
                                    @endif
                                </span>
                            </figcaption>
                        </figure>
                    </li>
                    @endforeach
                </ul>
            </div>
        </section>
        @endif

        @if(!empty($pricing['is_active']) && isset($pricing['plans']) && $pricing['plans']->isNotEmpty())
        <section class="l5-section l5-price" id="pricing" aria-labelledby="l5-price-title">
            <div class="l5-wrap">
                <header class="l5-section__head--center l5-reveal">
                    <p class="l5-eyebrow">{{ __('landing.pricing') }}</p>
                    @if($pricing['settings'])
                        <h2 class="l5-h2" id="l5-price-title">{{ $pricing['settings']->section_title }}</h2>
                        <p class="l5-lead">{{ $pricing['settings']->section_subtitle }}</p>
                    @else
                        <h2 class="l5-h2" id="l5-price-title">{{ __('landing.pricing') }}</h2>
                    @endif
                </header>
                <div class="l5-pgrid" role="list">
                    @foreach($pricing['plans'] as $i => $plan)
                    <article class="l5-pcard l5-reveal {{ $i === 1 ? 'l5-pcard--hit' : '' }}" role="listitem">
                        @if($i === 1)
                            <span class="l5-pcard__badge">{{ __('landing.most_popular') }}</span>
                        @endif
                        <header class="l5-pcard__head">
                            <h3 class="l5-pcard__name">{{ $plan->name }}</h3>
                            <p class="l5-pcard__hint">
                                @if($plan->price == 0)
                                    {{ __('landing.perfect_to_start') ?? 'Perfect to get started' }}
                                @else
                                    {{ __('landing.for_growing_teams') ?? 'For growing teams' }}
                                @endif
                            </p>
                        </header>
                        <div class="l5-pcard__price" role="group" aria-label="{{ $plan->name }}">
                            @if($plan->price == 0 && $plan->yearly_price == 0)
                                <span class="l5-pcard__amt">{{ __('landing.free') }}</span>
                            @else
                                <span class="l5-pcard__amt"><span class="l5-pcard__cur">{{ $currencySymbol }}</span>{{ $plan->price == floor($plan->price) ? number_format($plan->price, 0) : number_format($plan->price, 2) }}</span>
                                <span class="l5-pcard__per">/ {{ __('landing.mo') }}</span>
                            @endif
                        </div>
                        @if($plan->yearly_price > 0)
                            <p class="l5-pcard__year">
                                {{ __('landing.or') }} {{ $currencySymbol }}{{ $plan->yearly_price == floor($plan->yearly_price) ? number_format($plan->yearly_price, 0) : number_format($plan->yearly_price, 2) }} / {{ __('landing.yr') }}
                                @if($plan->getYearlySavingsPercent() > 0)
                                    <span class="l5-pcard__save">{{ __('landing.save') }} {{ $plan->getYearlySavingsPercent() }}%</span>
                                @endif
                            </p>
                        @endif
                        @php
                            $limits = $plan->limits ?? [];
                            $planFeatures = $plan->features ?? [];
                        @endphp
                        <ul class="l5-pcard__list">
                            @foreach($limits as $key => $value)
                                @php $meta = \App\Models\Central\Plan::AVAILABLE_LIMITS[$key] ?? null; @endphp
                                @if($meta)
                                    <li>
                                        <i class="bi bi-check-lg" aria-hidden="true"></i>
                                        <span>{{ $value == -1 ? __('landing.unlimited') : $value }} {{ $meta['label'] }}</span>
                                    </li>
                                @endif
                            @endforeach
                            @foreach($planFeatures as $fKey)
                                @php $fMeta = \App\Models\Central\Plan::AVAILABLE_FEATURES[$fKey] ?? null; @endphp
                                @if($fMeta)
                                    <li>
                                        <i class="bi bi-check-lg" aria-hidden="true"></i>
                                        <span>{{ $fMeta['label'] }}</span>
                                    </li>
                                @endif
                            @endforeach
                        </ul>
                        <footer class="l5-pcard__foot">
                            <a href="{{ route('central.register', ['plan' => $plan->id]) }}" class="l5-pcard__cta">
                                {{ $plan->isFree() ? __('landing.get_started') : ($plan->hasTrial() ? __('landing.start_free_trial') : __('landing.choose_plan')) }}
                            </a>
                        </footer>
                    </article>
                    @endforeach
                </div>
            </div>
        </section>
        @endif

        @if(!empty($faqs) && $faqs->isNotEmpty())
        <section class="l5-section l5-faq" id="faq" aria-labelledby="l5-faq-title">
            <div class="l5-wrap">
                <header class="l5-section__head--center l5-reveal">
                    <p class="l5-eyebrow">{{ __('landing.faq') }}</p>
                    <h2 class="l5-h2" id="l5-faq-title">{{ __('landing.faq_title') }}</h2>
                    <p class="l5-lead">{{ __('landing.faq_subtitle') }}</p>
                </header>
                <ol class="l5-faq__list">
                    @foreach($faqs as $faq)
                    <li class="l5-faq__li">
                        <details class="l5-faq__item l5-reveal">
                            <summary>
                                <span>{{ $faq->question }}</span>
                                <i class="bi bi-chevron-down l5-faq__chev" aria-hidden="true"></i>
                            </summary>
                            <div class="l5-faq__body">{!! nl2br(e($faq->answer)) !!}</div>
                        </details>
                    </li>
                    @endforeach
                </ol>
            </div>
        </section>
        @endif

        @if($cta)
        <aside class="l5-cta" id="cta" aria-labelledby="l5-cta-title">
            <div class="l5-wrap">
                <div class="l5-cta__box l5-reveal">
                    <div class="l5-cta__inner">
                        <h2 class="l5-cta__title" id="l5-cta-title">{{ $cta->title }}</h2>
                        @if($cta->subtitle)
                            <p class="l5-cta__sub">{{ $cta->subtitle }}</p>
                        @endif
                        @if($cta->button_text)
                            <p class="l5-cta__action">
                                <a href="{{ $cta->button_url ?? route('central.register') }}" class="l5-cta__btn">
                                    {{ $cta->button_text }} <i class="bi bi-arrow-right" aria-hidden="true"></i>
                                </a>
                            </p>
                        @endif
                    </div>
                </div>
            </div>
        </aside>
        @endif
    </main>

    <footer class="l5-footer">
        <div class="l5-wrap">
            <div class="l5-footer__grid">
                <div>
                    @if($generalSettings->show_site_name ?? true)
                        <a class="l5-footer__brand" href="{{ route('central.welcome') }}">{{ $appName }}</a>
                    @endif
                    @if($footer && $footer->footer_about)
                        <p class="l5-footer__about">{{ $footer->footer_about }}</p>
                    @endif
                    @if($footer)
                    <div class="l5-footer__social">
                        @foreach([
                            'facebook' => ['icon' => 'bi-facebook', 'label' => 'Facebook'],
                            'twitter' => ['icon' => 'bi-twitter-x', 'label' => 'X'],
                            'linkedin' => ['icon' => 'bi-linkedin', 'label' => 'LinkedIn'],
                            'instagram' => ['icon' => 'bi-instagram', 'label' => 'Instagram'],
                            'youtube' => ['icon' => 'bi-youtube', 'label' => 'YouTube'],
                        ] as $social => $meta)
                            @if($footer->$social)
                                <a href="{{ $footer->$social }}" target="_blank" rel="noopener noreferrer" aria-label="{{ $meta['label'] }}">
                                    <i class="bi {{ $meta['icon'] }}" aria-hidden="true"></i>
                                </a>
                            @endif
                        @endforeach
                    </div>
                    @endif
                </div>
                <div>
                    <p class="l5-footer__h">{{ __('landing.quick_links') }}</p>
                    <nav class="l5-footer__links" aria-label="{{ __('landing.quick_links') }}">
                        <ul>
                            <li><a href="{{ route('central.welcome') }}">{{ __('landing.home') }}</a></li>
                            @if(!empty($features['is_active']))
                                <li><a href="#features">{{ __('landing.features') }}</a></li>
                            @endif
                            @if(!empty($howItWorks['is_active']))
                                <li><a href="#journey">{{ __('landing.how_it_works') }}</a></li>
                            @endif
                            @if(!empty($pricing['is_active']))
                                <li><a href="#pricing">{{ __('landing.pricing') }}</a></li>
                            @endif
                            @if(!empty($testimonials) && $testimonials->isNotEmpty())
                                <li><a href="#voices">{{ __('landing.testimonials') }}</a></li>
                            @endif
                            @if(!empty($faqs) && $faqs->isNotEmpty())
                                <li><a href="#faq">{{ __('landing.faq') }}</a></li>
                            @endif
                            <li><a href="{{ route('central.register') }}">{{ __('landing.sign_up') }}</a></li>
                            @if($footer && ($footer->show_admin_login ?? false))
                                <li><a href="{{ route('central.login') }}">{{ __('landing.admin_login') }}</a></li>
                            @endif
                            <li><a href="{{ route('central.privacy-policy') }}">{{ __('landing.privacy_policy') }}</a></li>
                            <li><a href="{{ route('central.terms-conditions') }}">{{ __('landing.terms_and_conditions') }}</a></li>
                            <li><a href="#" id="cookiePreferencesLink">{{ __('landing.cookie_preferences_link') }}</a></li>
                        </ul>
                    </nav>
                </div>
                <div>
                    @if($footer && ($footer->contact_email || $footer->contact_phone || $footer->address))
                    <p class="l5-footer__h">{{ __('landing.contact') }}</p>
                    <div class="l5-footer__contact">
                        @if($footer->contact_email)
                            <a href="mailto:{{ $footer->contact_email }}">
                                <i class="bi bi-envelope" aria-hidden="true"></i>
                                <span>{{ $footer->contact_email }}</span>
                            </a>
                        @endif
                        @if($footer->contact_phone)
                            <a href="tel:{{ preg_replace('/[^0-9+]/', '', $footer->contact_phone) }}">
                                <i class="bi bi-telephone" aria-hidden="true"></i>
                                <span>{{ $footer->contact_phone }}</span>
                            </a>
                        @endif
                        @if($footer->address)
                            <span class="l5-footer__address">
                                <i class="bi bi-geo-alt" aria-hidden="true"></i>
                                <span>{{ $footer->address }}</span>
                            </span>
                        @endif
                    </div>
                    @endif
                </div>
            </div>
            <div class="l5-footer__bottom">
                <p class="l5-footer__copyright">{{ optional($footer)->copyright_text ?: ('© ' . date('Y') . ' ' . $appName . '. All rights reserved.') }}</p>
            </div>
        </div>
    </footer>

    <div class="l5-cookie" id="cookieConsent">
        <h4>{{ __('landing.cookie_banner_title') }}</h4>
        <p>
            {{ __('landing.cookie_banner_text') }}
            <a href="{{ route('central.privacy-policy') }}#cookies">{{ __('landing.privacy_policy') }}</a>
        </p>
        <div class="l5-cookie__actions">
            <button type="button" class="l5-cookie__go" id="cookieAcceptBtn">{{ __('landing.cookie_accept_all') }}</button>
            <button type="button" id="cookieRejectBtn">{{ __('landing.cookie_reject_all') }}</button>
            <button type="button" id="cookieCustomizeBtn">{{ __('landing.cookie_customize') }}</button>
        </div>
        <div id="cookieCustomize">
            <p>{{ __('landing.cookie_analytics') }}</p>
            <label>
                <input type="checkbox" id="cookieAnalytics"> {{ __('landing.cookie_analytics') }}
            </label>
            <label>
                <input type="checkbox" id="cookieMarketing"> {{ __('landing.cookie_marketing') }}
            </label>
            <button type="button" class="l5-cookie__go" id="cookieSaveBtn">{{ __('landing.cookie_save_preferences') }}</button>
        </div>
    </div>

    <script src="{{ asset('assets_super/js/landing-five.js') }}"></script>
</body>
</html>
