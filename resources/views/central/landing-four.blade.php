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
    <meta name="color-scheme" content="dark">
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
    <link href="{{ asset('assets_super/css/ibm-plex-sans.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/outfit.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/landing-four.css') }}" rel="stylesheet">
    @include('central.partials.landing-font')
</head>
<body class="l4-body">
    <header class="l4-header" id="l4Header">
        <div class="l4-wrap l4-header__bar">
            <a class="l4-brand" href="{{ route('central.welcome') }}">
                <span class="l4-brand__mark">
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

            <nav class="l4-nav-desktop" aria-label="{{ __('landing.quick_links') }}">
                @if(!empty($features['is_active']))
                    <a href="#features">{{ __('landing.features') }}</a>
                @endif
                @if(!empty($howItWorks['is_active']))
                    <a href="#process">{{ __('landing.how_it_works') }}</a>
                @endif
                @if(!empty($pricing['is_active']))
                    <a href="#pricing">{{ __('landing.pricing') }}</a>
                @endif
                @if(!empty($faqs) && $faqs->isNotEmpty())
                    <a href="#faq">{{ __('landing.faq') }}</a>
                @endif
            </nav>

            <div class="l4-header__actions">
                @if(isset($languages) && $languages->count() > 1)
                <div class="l4-lang" id="l4Lang">
                    <button type="button" class="l4-lang__btn" id="l4LangBtn" aria-expanded="false" aria-haspopup="true">
                        <i class="bi bi-globe2" aria-hidden="true"></i>
                        {{ strtoupper($currentLocale ?? app()->getLocale()) }}
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </button>
                    <div class="l4-lang__dropdown" role="menu">
                        @foreach($languages as $lang)
                        <form method="POST" action="{{ route('central.locale', $lang->locale) }}">
                            @csrf
                            <button type="submit" class="{{ ($currentLocale ?? app()->getLocale()) === $lang->locale ? 'active' : '' }}" role="menuitem">
                                @if($lang->flag)
                                    <img src="{{ asset('flags/' . $lang->flag) }}" alt="" class="l4-flag">
                                @endif
                                {{ $lang->name }}
                            </button>
                        </form>
                        @endforeach
                    </div>
                </div>
                @endif
                @if($footer && ($footer->show_admin_login ?? false))
                    <a class="l4-btn l4-btn--ghost" data-l4-desktop href="{{ route('central.login') }}">{{ __('landing.admin_login') }}</a>
                @endif
                <a class="l4-btn l4-btn--primary" href="{{ route('central.register') }}">{{ __('landing.sign_up_free') }}</a>
                <button type="button" class="l4-menu-btn" id="l4OpenMenu" aria-expanded="false" aria-controls="l4Drawer" aria-label="Menu">
                    <i class="bi bi-list" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </header>

    <div class="l4-drawer" id="l4Drawer" hidden aria-hidden="true">
        <div class="l4-drawer__panel" role="dialog" aria-modal="true" aria-label="Menu">
            <div class="l4-drawer__close">
                <button type="button" class="l4-menu-btn" id="l4CloseMenu" aria-label="Close">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
            </div>
            <nav class="l4-drawer__nav" aria-label="{{ __('landing.quick_links') }}">
                @if(!empty($features['is_active']))
                    <a href="#features">{{ __('landing.features') }}</a>
                @endif
                @if(!empty($howItWorks['is_active']))
                    <a href="#process">{{ __('landing.how_it_works') }}</a>
                @endif
                @if(!empty($pricing['is_active']))
                    <a href="#pricing">{{ __('landing.pricing') }}</a>
                @endif
                @if(!empty($faqs) && $faqs->isNotEmpty())
                    <a href="#faq">{{ __('landing.faq') }}</a>
                @endif
            </nav>
            <div class="l4-drawer__actions">
                @if($footer && ($footer->show_admin_login ?? false))
                    <a class="l4-btn l4-btn--outline" href="{{ route('central.login') }}">{{ __('landing.admin_login') }}</a>
                @endif
                <a class="l4-btn l4-btn--primary" href="{{ route('central.register') }}">{{ __('landing.sign_up_free') }}</a>
            </div>
        </div>
    </div>

    <main id="main">
        <section class="l4-hero" id="top" aria-labelledby="l4-hero-title">
            <div class="l4-hero__glow" aria-hidden="true"></div>
            <div class="l4-wrap l4-hero__centered">
                <div class="l4-hero__intro">
                    @if($hero && $hero->subtitle)
                        <p class="l4-eyebrow">{{ $hero->subtitle }}</p>
                    @else
                        <p class="l4-eyebrow">{{ __('landing.trust_bar_eyebrow') }}</p>
                    @endif
                    <h1 class="l4-hero__title" id="l4-hero-title">
                        @if($hero)
                            {!! $hero->title !!}
                        @else
                            {{ $appName }}
                        @endif
                    </h1>
                    @if($hero && $hero->description)
                        <p class="l4-hero__sub">{{ $hero->description }}</p>
                    @elseif(!$hero)
                        <p class="l4-hero__sub">{{ __('landing.testimonials_subtitle') }}</p>
                    @endif
                    <div class="l4-hero__cta">
                        @if($hero && $hero->primary_button_text)
                            <a href="{{ $hero->primary_button_url ?? route('central.register') }}" class="l4-btn l4-btn--primary l4-btn--lg">
                                {{ $hero->primary_button_text }} <i class="bi bi-arrow-right" aria-hidden="true"></i>
                            </a>
                        @else
                            <a href="{{ route('central.register') }}" class="l4-btn l4-btn--primary l4-btn--lg">
                                {{ __('landing.sign_up_free') }} <i class="bi bi-arrow-right" aria-hidden="true"></i>
                            </a>
                        @endif
                        @if($hero && $hero->secondary_button_text)
                            <a href="{{ $hero->secondary_button_url ?? route('central.register') }}" class="l4-btn l4-btn--outline l4-btn--lg">{{ $hero->secondary_button_text }}</a>
                        @endif
                    </div>
                    @if(session('success') && session('tenant_url'))
                        <div class="l4-alert" role="status">
                            {{ session('message') }}<br>
                            <a href="{{ session('tenant_url') }}">{{ session('tenant_url') }}</a>
                        </div>
                    @endif
                </div>
                @if($hero && $hero->hero_image)
                <div class="l4-hero__visual">
                    <img src="{{ asset($hero->hero_image) }}" alt="{{ strip_tags($hero->title ?? $appName) }}" decoding="async">
                </div>
                @endif
            </div>
        </section>

        @if((isset($stats) && $stats->isNotEmpty()) || (!empty($testimonials) && $testimonials->isNotEmpty()))
        <section class="l4-section l4-proof" id="proof" aria-labelledby="l4-proof-title">
            <div class="l4-wrap">
                @if(isset($stats) && $stats->isNotEmpty())
                    <header class="l4-section__head l4-reveal">
                        <p class="l4-eyebrow">{{ __('landing.trust_bar_eyebrow') }}</p>
                        <h2 class="l4-h2" id="l4-proof-title">{{ __('landing.trust_bar_aria') }}</h2>
                    </header>
                    <div class="l4-metrics">
                        @foreach($stats as $stat)
                        <div class="l4-metric l4-reveal">
                            <span class="l4-metric__value">{{ $stat->value }}</span>
                            <span class="l4-metric__label">{{ $stat->label }}</span>
                        </div>
                        @endforeach
                    </div>
                @endif

                @if(!empty($testimonials) && $testimonials->isNotEmpty())
                    @if(isset($stats) && $stats->isNotEmpty())
                        <div class="l4-proof__rule" aria-hidden="true"></div>
                    @else
                        <header class="l4-section__head l4-section__head--compact l4-reveal">
                            <p class="l4-eyebrow">{{ __('landing.testimonials') }}</p>
                            <h2 class="l4-h2">{{ __('landing.loved_by_businesses') }}</h2>
                            <p class="l4-lead">{{ __('landing.testimonials_subtitle') }}</p>
                        </header>
                    @endif
                    @if(isset($stats) && $stats->isNotEmpty())
                        <p class="l4-proof__label">{{ __('landing.testimonials') }}</p>
                    @endif
                    <div class="l4-quotes" role="list">
                        @foreach($testimonials as $t)
                        <article class="l4-quote l4-reveal" role="listitem">
                            <p class="l4-quote__text">“{{ $t->review }}”</p>
                            <div class="l4-quote__row">
                                <div class="l4-quote__avatar">
                                    @if($t->avatar)
                                        <img src="{{ asset($t->avatar) }}" alt="" decoding="async">
                                    @else
                                        {{ strtoupper(mb_substr($t->client_name, 0, 1)) }}
                                    @endif
                                </div>
                                <div>
                                    <p class="l4-quote__name">{{ $t->client_name }}</p>
                                    @if($t->company_name)
                                        <span class="l4-quote__role">{{ $t->company_name }}</span>
                                    @endif
                                </div>
                            </div>
                        </article>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>
        @endif

        @if(!empty($features['is_active']) && $features['items']->isNotEmpty())
        <section class="l4-section l4-features" id="features" aria-labelledby="l4-features-title">
            <div class="l4-wrap">
                <header class="l4-section__head--center l4-reveal">
                    <p class="l4-eyebrow">{{ __('landing.features') }}</p>
                    @if($features['section'])
                        <h2 class="l4-h2" id="l4-features-title">{{ $features['section']->section_title }}</h2>
                        <p class="l4-lead">{{ $features['section']->section_subtitle }}</p>
                    @else
                        <h2 class="l4-h2" id="l4-features-title">{{ __('landing.features') }}</h2>
                    @endif
                </header>
                <div class="l4-fgrid">
                    @foreach($features['items'] as $feature)
                    <article class="l4-fcard l4-reveal">
                        <div class="l4-fcard__icon" aria-hidden="true">
                            @if($feature->image)
                                <img src="{{ asset($feature->image) }}" alt="" decoding="async">
                            @elseif($feature->icon)
                                <i class="{{ $feature->icon }}"></i>
                            @else
                                <i class="bi bi-stars"></i>
                            @endif
                        </div>
                        <h3 class="l4-fcard__title">{{ $feature->title }}</h3>
                        @if($feature->description)
                            <p class="l4-fcard__desc">{{ $feature->description }}</p>
                        @endif
                    </article>
                    @endforeach
                </div>
            </div>
        </section>
        @endif

        @if(!empty($howItWorks['is_active']))
        <section class="l4-section l4-process" id="process" aria-labelledby="l4-process-title">
            <div class="l4-wrap">
                <header class="l4-section__head--center l4-reveal">
                    <p class="l4-eyebrow">{{ $howItWorks['section']->section_label ?? __('landing.how_it_works') }}</p>
                    <h2 class="l4-h2" id="l4-process-title">{{ $howItWorks['section']->section_title ?? __('landing.how_it_works_title') }}</h2>
                    <p class="l4-lead">{{ $howItWorks['section']->section_subtitle ?? __('landing.how_it_works_subtitle') }}</p>
                </header>
                <div class="l4-flow">
                    @forelse($howItWorks['steps'] as $i => $step)
                    <div class="l4-flow__step l4-reveal">
                        <span class="l4-flow__num">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>
                        <h3 class="l4-flow__title">{{ $step->title }}</h3>
                        <p class="l4-flow__desc">{{ $step->description }}</p>
                    </div>
                    @empty
                    <div class="l4-flow__step l4-reveal">
                        <span class="l4-flow__num">01</span>
                        <h3 class="l4-flow__title">{{ __('landing.hiw_features_title') }}</h3>
                        <p class="l4-flow__desc">{{ __('landing.hiw_features_desc') }}</p>
                    </div>
                    <div class="l4-flow__step l4-reveal">
                        <span class="l4-flow__num">02</span>
                        <h3 class="l4-flow__title">{{ __('landing.hiw_pricing_title') }}</h3>
                        <p class="l4-flow__desc">{{ __('landing.hiw_pricing_desc') }}</p>
                    </div>
                    <div class="l4-flow__step l4-reveal">
                        <span class="l4-flow__num">03</span>
                        <h3 class="l4-flow__title">{{ __('landing.hiw_data_title') }}</h3>
                        <p class="l4-flow__desc">{{ __('landing.hiw_data_desc') }}</p>
                    </div>
                    @endforelse
                </div>
            </div>
        </section>
        @endif

        @if(!empty($pricing['is_active']) && isset($pricing['plans']) && $pricing['plans']->isNotEmpty())
        <section class="l4-section l4-price" id="pricing" aria-labelledby="l4-price-title">
            <div class="l4-wrap">
                <header class="l4-section__head--center l4-reveal">
                    <p class="l4-eyebrow">{{ __('landing.pricing') }}</p>
                    @if($pricing['settings'])
                        <h2 class="l4-h2" id="l4-price-title">{{ $pricing['settings']->section_title }}</h2>
                        <p class="l4-lead">{{ $pricing['settings']->section_subtitle }}</p>
                    @else
                        <h2 class="l4-h2" id="l4-price-title">{{ __('landing.pricing') }}</h2>
                    @endif
                </header>
                <div class="l4-pgrid">
                    @foreach($pricing['plans'] as $i => $plan)
                    <article class="l4-pcard l4-reveal {{ $i === 1 ? 'l4-pcard--hit' : '' }}">
                        @if($i === 1)
                            <span class="l4-pcard__badge">{{ __('landing.most_popular') }}</span>
                        @endif
                        <h3 class="l4-pcard__name">{{ $plan->name }}</h3>
                        <p class="l4-pcard__hint">
                            @if($plan->price == 0)
                                {{ __('landing.perfect_to_start') ?? 'Perfect to get started' }}
                            @else
                                {{ __('landing.for_growing_teams') ?? 'For growing teams' }}
                            @endif
                        </p>
                        <div class="l4-pcard__price">
                            @if($plan->price == 0 && $plan->yearly_price == 0)
                                <span class="l4-pcard__amt">{{ __('landing.free') }}</span>
                            @else
                                <span class="l4-pcard__amt"><span class="l4-pcard__cur">{{ $currencySymbol }}</span>{{ $plan->price == floor($plan->price) ? number_format($plan->price, 0) : number_format($plan->price, 2) }}</span>
                                <span class="l4-pcard__per">/ {{ __('landing.mo') }}</span>
                            @endif
                        </div>
                        @if($plan->yearly_price > 0)
                            <p class="l4-pcard__year">
                                {{ __('landing.or') }} {{ $currencySymbol }}{{ $plan->yearly_price == floor($plan->yearly_price) ? number_format($plan->yearly_price, 0) : number_format($plan->yearly_price, 2) }} / {{ __('landing.yr') }}
                                @if($plan->getYearlySavingsPercent() > 0)
                                    <span class="l4-pcard__save">{{ __('landing.save') }} {{ $plan->getYearlySavingsPercent() }}%</span>
                                @endif
                            </p>
                        @endif
                        @php
                            $limits = $plan->limits ?? [];
                            $planFeatures = $plan->features ?? [];
                        @endphp
                        <ul class="l4-pcard__list">
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
                        <a href="{{ route('central.register', ['plan' => $plan->id]) }}" class="l4-pcard__cta">
                            {{ $plan->isFree() ? __('landing.get_started') : ($plan->hasTrial() ? __('landing.start_free_trial') : __('landing.choose_plan')) }}
                        </a>
                    </article>
                    @endforeach
                </div>
            </div>
        </section>
        @endif

        @if(!empty($faqs) && $faqs->isNotEmpty())
        <section class="l4-section l4-faq" id="faq" aria-labelledby="l4-faq-title">
            <div class="l4-wrap">
                <header class="l4-section__head--center l4-reveal">
                    <p class="l4-eyebrow">{{ __('landing.faq') }}</p>
                    <h2 class="l4-h2" id="l4-faq-title">{{ __('landing.faq_title') }}</h2>
                    <p class="l4-lead">{{ __('landing.faq_subtitle') }}</p>
                </header>
                <div class="l4-faq__list">
                    @foreach($faqs as $faq)
                    <details class="l4-faq__item l4-reveal">
                        <summary>
                            <span>{{ $faq->question }}</span>
                            <i class="bi bi-chevron-down l4-faq__chev" aria-hidden="true"></i>
                        </summary>
                        <div class="l4-faq__body">{!! nl2br(e($faq->answer)) !!}</div>
                    </details>
                    @endforeach
                </div>
            </div>
        </section>
        @endif

        @if($cta)
        <section class="l4-cta" id="cta" aria-labelledby="l4-cta-title">
            <div class="l4-wrap">
                <div class="l4-cta__box l4-reveal">
                    <div class="l4-cta__inner">
                        <h2 class="l4-cta__title" id="l4-cta-title">{{ $cta->title }}</h2>
                        @if($cta->subtitle)
                            <p class="l4-cta__sub">{{ $cta->subtitle }}</p>
                        @endif
                        @if($cta->button_text)
                            <a href="{{ $cta->button_url ?? route('central.register') }}" class="l4-cta__btn">
                                {{ $cta->button_text }} <i class="bi bi-arrow-right" aria-hidden="true"></i>
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        </section>
        @endif
    </main>

    <footer class="l4-footer">
        <div class="l4-wrap">
            <div class="l4-footer__grid">
                <div>
                    @if($generalSettings->show_site_name ?? true)
                        <a class="l4-footer__brand" href="{{ route('central.welcome') }}">{{ $appName }}</a>
                    @endif
                    @if($footer && $footer->footer_about)
                        <p class="l4-footer__about">{{ $footer->footer_about }}</p>
                    @endif
                    @if($footer)
                    <div class="l4-footer__social">
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
                    <p class="l4-footer__h">{{ __('landing.quick_links') }}</p>
                    <nav class="l4-footer__links" aria-label="{{ __('landing.quick_links') }}">
                        <a href="{{ route('central.welcome') }}">{{ __('landing.home') }}</a>
                        @if(!empty($features['is_active']))
                            <a href="#features">{{ __('landing.features') }}</a>
                        @endif
                        @if(!empty($pricing['is_active']))
                            <a href="#pricing">{{ __('landing.pricing') }}</a>
                        @endif
                        @if(!empty($faqs) && $faqs->isNotEmpty())
                            <a href="#faq">{{ __('landing.faq') }}</a>
                        @endif
                        <a href="{{ route('central.register') }}">{{ __('landing.sign_up') }}</a>
                        @if($footer && ($footer->show_admin_login ?? false))
                            <a href="{{ route('central.login') }}">{{ __('landing.admin_login') }}</a>
                        @endif
                        <a href="{{ route('central.privacy-policy') }}">{{ __('landing.privacy_policy') }}</a>
                        <a href="{{ route('central.terms-conditions') }}">{{ __('landing.terms_and_conditions') }}</a>
                        <a href="#" id="cookiePreferencesLink">{{ __('landing.cookie_preferences_link') }}</a>
                    </nav>
                </div>
                <div>
                    @if($footer && ($footer->contact_email || $footer->contact_phone || $footer->address))
                    <p class="l4-footer__h">{{ __('landing.contact') }}</p>
                    <div class="l4-footer__contact">
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
                            <span class="l4-footer__address">
                                <i class="bi bi-geo-alt" aria-hidden="true"></i>
                                <span>{{ $footer->address }}</span>
                            </span>
                        @endif
                    </div>
                    @endif
                </div>
            </div>
            <div class="l4-footer__bottom">
                <p class="l4-footer__copyright">{{ optional($footer)->copyright_text ?: ('© ' . date('Y') . ' ' . $appName . '. All rights reserved.') }}</p>
            </div>
        </div>
    </footer>

    <div class="l4-cookie" id="cookieConsent">
        <h4>{{ __('landing.cookie_banner_title') }}</h4>
        <p>
            {{ __('landing.cookie_banner_text') }}
            <a href="{{ route('central.privacy-policy') }}#cookies">{{ __('landing.privacy_policy') }}</a>
        </p>
        <div class="l4-cookie__actions">
            <button type="button" class="l4-cookie__go" id="cookieAcceptBtn">{{ __('landing.cookie_accept_all') }}</button>
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
            <button type="button" class="l4-cookie__go" id="cookieSaveBtn">{{ __('landing.cookie_save_preferences') }}</button>
        </div>
    </div>

    <script src="{{ asset('assets_super/js/landing-four.js') }}"></script>
</body>
</html>
