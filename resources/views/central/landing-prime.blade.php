<!DOCTYPE html>
@php
    use App\Support\LandingContact;

    $isRtl = in_array(app()->getLocale(), ['ar', 'he', 'fa', 'ur']);
    $generalSettings = \App\Models\Central\GeneralSetting::instance();
    $appName = $generalSettings->app_name ?: 'PRODEX';
    $logoUrl = $generalSettings->getLogoUrl();

    $lpHeroVisible = $hero && ($hero->is_active ?? true);
    $lpCtaVisible  = $cta && ($cta->is_active ?? true) && ($cta->show_commercial_cta ?? true);

    $lpSalesHref = LandingContact::salesUrl($footer, $cta) ?: '#contact-sales';
    $lpSalesExternal = LandingContact::isExternal($lpSalesHref);
    $lpSalesEmail = $footer->sales_email ?? $footer->contact_email ?? null;
    $lpSalesWhatsappRaw = $footer->sales_whatsapp_number ?? $footer->contact_phone ?? null;
    $lpSalesWhatsappDigits = $lpSalesWhatsappRaw ? preg_replace('/\D+/', '', $lpSalesWhatsappRaw) : null;
    $lpSalesWhatsappHref = $lpSalesWhatsappDigits
        ? 'https://wa.me/' . $lpSalesWhatsappDigits . '?text=' . rawurlencode(($footer->sales_whatsapp_message ?? null) ?: 'Hola, me interesa conocer más sobre Prodex y sus planes.')
        : null;

    // Un plan real con prueba => se muestra el microcopy de trial en el hero.
    $lpTrialPlan = ($plans ?? collect())->first(fn ($p) => $p->hasTrial());
    $lpTrialDays = $lpTrialPlan ? $lpTrialPlan->getTrialDays() : 0;

    $lpFaqs = isset($faqs) ? $faqs->filter(fn ($f) => ($f->is_active ?? true)) : collect();
    $lpTestimonials = isset($testimonials) ? $testimonials->filter(fn ($t) => ($t->is_active ?? true)) : collect();
    // "Cómo funciona" en landing-prime se resuelve siempre desde landing_prime.*
    // (ver esa sección); el CMS $howItWorks alimenta sólo la landing legada.

    $lpRegisterUrl = route('central.register');
@endphp
<html lang="{{ app()->getLocale() }}" class="scroll-smooth" @if($isRtl) dir="rtl" @endif>
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

    <script src="{{ asset('assets_super/js/tailwindcss.js') }}"></script>
    <link href="{{ asset('assets_super/css/inter.css') }}" rel="stylesheet">
    <script src="{{ asset('assets_super/js/iconify-icon.min.js') }}"></script>
    <link href="{{ asset('assets_super/css/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/landing-prime.css') }}" rel="stylesheet">
    <script>document.documentElement.classList.add('lp-js');</script>

    @include('central.partials.landing-font')

    {{-- Estructura de datos ADICIONAL: solo FAQPage, y solo si las FAQ del CMS
         realmente se renderizan. No se añade Product/Offer (ver deuda de datos). --}}
    @if($lpFaqs->isNotEmpty())
        <script type="application/ld+json">
        {!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $lpFaqs->map(fn ($f) => [
                '@type' => 'Question',
                'name' => (string) $f->question,
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => (string) $f->answer],
            ])->values()->all(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
        </script>
    @endif
</head>
<body class="landing-prime antialiased">
<a href="#lp-main" class="sr-only focus:not-sr-only focus:fixed focus:z-[60] focus:top-3 focus:left-3 focus:bg-white focus:text-slate-900 focus:px-4 focus:py-2 focus:rounded-lg focus:shadow">{{ __('landing_prime.skip_to_content') }}</a>

{{-- ═══════════════════════ NAVBAR ═══════════════════════ --}}
<nav id="lpNav" class="lp-nav">
    <div class="max-w-7xl mx-auto h-full px-5 sm:px-6 flex items-center justify-between gap-4">
        <a href="{{ route('central.welcome') }}" class="flex items-center gap-2.5 shrink-0">
            @if($logoUrl)
                <img src="{{ $logoUrl }}" alt="{{ $appName }}" class="h-7 w-auto">
            @else
                <span class="inline-grid place-items-center w-8 h-8 rounded-lg bg-slate-900 text-white font-bold text-sm">{{ strtoupper(substr($appName, 0, 1)) }}</span>
            @endif
            @if($generalSettings->show_site_name ?? true)
                <span class="font-bold text-slate-950">{{ $appName }}</span>
            @endif
        </a>

        <div class="hidden lg:flex items-center gap-1">
            <a href="#product" class="lp-nav__link">{{ __('landing_prime.nav_product') }}</a>
            <a href="#solutions" class="lp-nav__link">{{ __('landing_prime.nav_solutions') }}</a>
            <a href="#pricing" class="lp-nav__link">{{ __('landing_prime.nav_pricing') }}</a>
            @if($lpFaqs->isNotEmpty())
                <a href="#faq" class="lp-nav__link">{{ __('landing_prime.nav_resources') }}</a>
            @endif
        </div>

        <div class="flex items-center gap-2">
            @if(isset($languages) && $languages->count() > 1)
                <div class="relative hidden sm:block">
                    <button type="button" id="lpLangBtn" class="lp-nav__link inline-flex items-center gap-1.5" aria-haspopup="true" aria-expanded="false">
                        <i class="bi bi-globe2"></i>{{ strtoupper($currentLocale ?? app()->getLocale()) }}
                        <i class="bi bi-chevron-down text-[10px]"></i>
                    </button>
                    <div id="lpLangMenu" hidden class="absolute right-0 mt-2 w-40 bg-white border border-slate-200 rounded-xl shadow-lg p-1.5 z-50">
                        @foreach($languages as $lang)
                            <form method="POST" action="{{ route('central.locale', $lang->locale) }}">
                                @csrf
                                <button type="submit" class="w-full flex items-center gap-2 px-3 py-2 rounded-lg text-sm text-slate-700 hover:bg-slate-50 {{ ($currentLocale ?? app()->getLocale()) === $lang->locale ? 'font-semibold text-indigo-600' : '' }}">
                                    @if($lang->flag)<img src="{{ asset('flags/' . $lang->flag) }}" alt="" class="w-4 h-4 rounded-sm">@endif
                                    {{ $lang->name }}
                                </button>
                            </form>
                        @endforeach
                    </div>
                </div>
            @endif
            <a href="{{ route('central.login') }}" class="hidden sm:inline-flex lp-nav__link">{{ __('landing_prime.nav_login') }}</a>
            <a href="{{ $lpRegisterUrl }}" class="lp-btn lp-btn--primary hidden sm:inline-flex">{{ __('landing_prime.nav_cta') }}</a>
            <button type="button" id="lpMenuOpen" class="lg:hidden inline-grid place-items-center w-10 h-10 rounded-lg border border-slate-200 text-slate-700" aria-label="{{ __('landing_prime.nav_menu') }}" aria-expanded="false" aria-controls="lpDrawer">
                <i class="bi bi-list text-xl"></i>
            </button>
        </div>
    </div>
</nav>

{{-- Mobile drawer --}}
<div id="lpDrawer" class="lp-drawer fixed inset-0 z-[55] lg:hidden" hidden role="dialog" aria-modal="true" aria-label="{{ __('landing_prime.nav_menu') }}">
    <div id="lpDrawerBackdrop" class="absolute inset-0 bg-slate-900/40"></div>
    <div id="lpDrawerPanel" class="lp-drawer__panel absolute inset-y-0 right-0 w-[86%] max-w-sm bg-white shadow-2xl flex flex-col" style="transform:translateX(100%)">
        <div class="h-[68px] px-5 flex items-center justify-between border-b border-slate-100">
            <span class="font-bold text-slate-950">{{ $appName }}</span>
            <button type="button" id="lpMenuClose" class="inline-grid place-items-center w-11 h-11 rounded-lg border border-slate-200 text-slate-700" aria-label="{{ __('landing_prime.nav_close') }}"><i class="bi bi-x-lg"></i></button>
        </div>
        <nav class="flex-1 overflow-y-auto p-5 flex flex-col gap-1">
            <a href="#product" class="px-3 py-3 rounded-lg text-slate-800 font-medium hover:bg-slate-50">{{ __('landing_prime.nav_product') }}</a>
            <a href="#solutions" class="px-3 py-3 rounded-lg text-slate-800 font-medium hover:bg-slate-50">{{ __('landing_prime.nav_solutions') }}</a>
            <a href="#pricing" class="px-3 py-3 rounded-lg text-slate-800 font-medium hover:bg-slate-50">{{ __('landing_prime.nav_pricing') }}</a>
            @if($lpFaqs->isNotEmpty())
                <a href="#faq" class="px-3 py-3 rounded-lg text-slate-800 font-medium hover:bg-slate-50">{{ __('landing_prime.nav_resources') }}</a>
            @endif
            <a href="{{ $lpSalesHref }}" @if($lpSalesExternal) target="_blank" rel="noopener noreferrer" @endif class="px-3 py-3 rounded-lg text-slate-800 font-medium hover:bg-slate-50">{{ __('landing.talk_to_sales') }}</a>

            @if(isset($languages) && $languages->count() > 1)
                <p class="px-3 pt-4 pb-1 text-xs font-bold uppercase tracking-wide text-slate-400">{{ __('landing.language') }}</p>
                <div class="grid grid-cols-2 gap-1.5">
                    @foreach($languages as $lang)
                        <form method="POST" action="{{ route('central.locale', $lang->locale) }}">
                            @csrf
                            <button type="submit" class="w-full flex items-center gap-2 px-3 py-2.5 rounded-lg text-sm text-slate-700 border border-slate-200 hover:bg-slate-50 {{ ($currentLocale ?? app()->getLocale()) === $lang->locale ? 'font-semibold text-indigo-600 border-indigo-200' : '' }}">
                                @if($lang->flag)<img src="{{ asset('flags/' . $lang->flag) }}" alt="" class="w-4 h-4 rounded-sm">@endif
                                {{ $lang->name }}
                            </button>
                        </form>
                    @endforeach
                </div>
            @endif
        </nav>
        <div class="p-5 border-t border-slate-100 flex flex-col gap-2">
            <a href="{{ route('central.login') }}" class="lp-btn lp-btn--ghost w-full">{{ __('landing_prime.nav_login') }}</a>
            <a href="{{ $lpRegisterUrl }}" class="lp-btn lp-btn--primary w-full">{{ __('landing_prime.nav_cta') }}</a>
        </div>
    </div>
</div>

<main id="lp-main" style="padding-top:var(--lp-nav-h)">

    {{-- ═══════════════════════ HERO ═══════════════════════ --}}
    @if($lpHeroVisible)
    <section class="lp-soft lp-aurora relative overflow-hidden px-5 sm:px-6 pt-16 sm:pt-24 pb-20 sm:pb-32">
        <div class="max-w-7xl mx-auto grid lg:grid-cols-12 gap-y-14 gap-x-10 lg:gap-x-8 items-center">
            <div class="lg:col-span-5 lp-reveal">
                <span class="lp-mark mb-6"></span>
                <p class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-white/80 border border-slate-200 shadow-sm text-xs font-semibold text-slate-600 mb-6">
                    <span class="w-1.5 h-1.5 rounded-full" style="background:linear-gradient(90deg,var(--lp-aurora-1),var(--lp-aurora-3))"></span>
                    {{ $hero->subtitle ?? __('landing_prime.hero_eyebrow') }}
                </p>
                <h1 class="text-[2.6rem] leading-[1.04] sm:text-5xl lg:text-[3.6rem] xl:text-[4rem] font-bold tracking-[-0.035em] text-slate-950 mb-5 text-balance">
                    {!! $hero->title ?: e(__('landing_prime.hero_title')) !!}
                </h1>
                <p class="text-lg text-slate-600 leading-relaxed max-w-xl mb-8">
                    {{ $hero->description ?: __('landing_prime.hero_lead') }}
                </p>
                <div class="flex flex-col sm:flex-row gap-3">
                    <a href="{{ $hero->primary_button_url ?? $lpRegisterUrl }}" class="lp-btn lp-btn--primary lp-btn--lg">
                        {{ $hero->primary_button_text ?: __('landing_prime.hero_cta') }}
                        <i class="bi bi-arrow-right"></i>
                    </a>
                    <a href="{{ $hero->secondary_button_url ?? $lpSalesHref }}" @if($lpSalesExternal && ! $hero->secondary_button_url) target="_blank" rel="noopener noreferrer" @endif class="lp-btn lp-btn--ghost lp-btn--lg">
                        {{ $hero->secondary_button_text ?: __('landing_prime.hero_cta_secondary') }}
                    </a>
                </div>
                @if($lpTrialPlan)
                    <p class="mt-4 text-xs font-medium text-slate-500 flex items-center gap-1.5">
                        <i class="bi bi-check-circle-fill text-emerald-500"></i>
                        {{ __('landing_prime.hero_trust', ['days' => $lpTrialDays]) }}
                    </p>
                @endif
                <div class="mt-6 flex flex-wrap gap-x-5 gap-y-2 text-xs font-medium text-slate-500">
                    @foreach(['hero_chip_sales', 'hero_chip_stock', 'hero_chip_reports', 'hero_chip_branches'] as $chip)
                        <span class="flex items-center gap-1.5"><i class="bi bi-check-circle-fill text-emerald-500"></i>{{ __('landing_prime.' . $chip) }}</span>
                    @endforeach
                </div>
            </div>

            <div class="lg:col-span-7 lp-reveal" data-delay="1">
                @if($hero->hero_image)
                    <div class="lp-window">
                        <div class="lp-window__bar"><span class="lp-window__dot"></span><span class="lp-window__dot"></span><span class="lp-window__dot"></span><span class="lp-window__title">{{ __('landing_prime.hero_mock_title') }}</span></div>
                        <img src="{{ asset($hero->hero_image) }}" alt="{{ strip_tags($hero->title ?: $appName) }}" class="w-full h-auto block" decoding="async">
                    </div>
                @else
                    {{-- Escena de producto: patrones reales del ERP, valores neutros, sin cifras. --}}
                    <div class="relative lg:px-6">
                        <div class="lp-window">
                            <div class="lp-window__bar">
                                <span class="lp-window__dot"></span><span class="lp-window__dot"></span><span class="lp-window__dot"></span>
                                <span class="lp-window__title">{{ __('landing_prime.hero_mock_title') }}</span>
                            </div>
                            <div class="lp-appui">
                                <div class="lp-appui__rail" aria-hidden="true">
                                    <i class="bi bi-grid-1x2-fill is-active"></i>
                                    <i class="bi bi-bag"></i>
                                    <i class="bi bi-box-seam"></i>
                                    <i class="bi bi-truck"></i>
                                    <i class="bi bi-graph-up"></i>
                                    <i class="bi bi-people"></i>
                                </div>
                                <div class="lp-appui__main">
                                    <div class="lp-appui__topbar">
                                        <i class="bi bi-buildings text-indigo-500"></i>
                                        <span>{{ __('landing_prime.hero_mock_title') }}</span>
                                        <span class="lp-appui__pin" aria-hidden="true"></span>
                                    </div>
                                    <div class="lp-kpi" aria-hidden="true">
                                        <div><b>{{ __('landing_prime.value_sales') }}</b><span class="lp-spark"><span style="height:40%"></span><span style="height:62%"></span><span style="height:48%"></span><span style="height:78%"></span><span style="height:66%"></span></span></div>
                                        <div><b>{{ __('landing_prime.value_inventory') }}</b><span class="lp-spark"><span style="height:70%"></span><span style="height:52%"></span><span style="height:60%"></span><span style="height:44%"></span><span style="height:58%"></span></span></div>
                                        <div><b>{{ __('landing_prime.value_reports') }}</b><span class="lp-spark"><span style="height:34%"></span><span style="height:58%"></span><span style="height:72%"></span><span style="height:60%"></span><span style="height:84%"></span></span></div>
                                    </div>
                                    <div class="grid grid-cols-[1fr_auto] gap-x-4 text-[11px] font-semibold uppercase tracking-wide text-slate-400 pb-2 border-b border-slate-100">
                                        <span>{{ __('landing_prime.hero_mock_row_product') }}</span>
                                        <span>{{ __('landing_prime.showcase_col_status') }}</span>
                                    </div>
                                    @php $st = [['ok','hero_mock_st_ok'],['wait','hero_mock_st_sync'],['info','hero_mock_st_low'],['ok','hero_mock_st_ok']]; @endphp
                                    @for($r = 0; $r < 4; $r++)
                                        <div class="grid grid-cols-[1fr_auto] gap-x-4 items-center py-2.5 border-b border-slate-50">
                                            <span class="h-2.5 rounded bg-slate-100" style="width: {{ [72, 54, 84, 46][$r] }}%"></span>
                                            <span class="lp-pill lp-pill--{{ $st[$r][0] }}"><i class="bi bi-circle-fill"></i>{{ __('landing_prime.' . $st[$r][1]) }}</span>
                                        </div>
                                    @endfor
                                </div>
                            </div>
                        </div>
                        <div class="hidden lg:flex lp-chip absolute -left-10 xl:-left-16 top-1/2 -translate-y-1/2 w-52 z-10 lp-reveal" data-delay="2">
                            <span class="lp-chip__ic"><i class="bi bi-receipt"></i></span>
                            <span><b>{{ __('landing_prime.hero_mock_card_pos') }}</b><span>{{ __('landing_prime.hero_mock_card_pos_desc') }}</span></span>
                        </div>
                        <div class="hidden lg:flex lp-chip absolute -right-8 xl:-right-14 bottom-10 w-52 z-10 lp-reveal" data-delay="3">
                            <span class="lp-chip__ic"><i class="bi bi-box-seam"></i></span>
                            <span><b>{{ __('landing_prime.hero_mock_card_stock') }}</b><span>{{ __('landing_prime.hero_mock_card_stock_desc') }}</span></span>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        @if(session('success') && session('tenant_url'))
            <div class="max-w-3xl mx-auto mt-8 text-center bg-white border border-emerald-200 rounded-xl p-4 text-sm text-slate-700">
                {{ session('message') }}<br><a class="text-indigo-600 font-semibold" href="{{ session('tenant_url') }}">{{ session('tenant_url') }}</a>
            </div>
        @endif
    </section>
    @endif

    {{-- ═══════════════════════ VALUE BAR ═══════════════════════ --}}
    <section class="bg-white py-14 px-5 sm:px-6">
        <div class="max-w-5xl mx-auto text-center lp-reveal">
            <p class="text-lg sm:text-xl font-semibold text-slate-800 max-w-2xl mx-auto mb-8">{{ __('landing_prime.value_headline') }}</p>
            <div class="flex flex-wrap justify-center gap-2.5">
                @foreach(['value_pos' => 'bi-shop-window', 'value_inventory' => 'bi-box-seam', 'value_purchases' => 'bi-truck', 'value_sales' => 'bi-receipt', 'value_reports' => 'bi-graph-up', 'value_hr' => 'bi-people', 'value_branches' => 'bi-buildings', 'value_ecommerce' => 'bi-bag'] as $key => $icon)
                    <span class="inline-flex items-center gap-2 px-4 py-2 rounded-full border border-slate-200 bg-slate-50 text-sm font-medium text-slate-700">
                        <i class="bi {{ $icon }} text-indigo-500"></i>{{ __('landing_prime.' . $key) }}
                    </span>
                @endforeach
            </div>
            @if(!empty($stats) && $stats->isNotEmpty())
                <div class="mt-12 grid grid-cols-2 md:grid-cols-4 gap-6 max-w-3xl mx-auto">
                    @foreach($stats as $stat)
                        <div>
                            <div class="text-2xl sm:text-3xl font-extrabold text-slate-950 lp-tnum">{{ $stat->value }}</div>
                            <div class="text-xs text-slate-500 mt-1">{{ $stat->label }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    {{-- ═══════════════════════ CALCULADORA ═══════════════════════ --}}
    @include('central.partials.prime.calculator')

    {{-- ═══════════════════════ PLANES (siempre visibles) ═══════════════════════ --}}
    @include('central.partials.prime.plans')

    {{-- ═══════════════════════ MÓDULOS / SOLUCIONES ═══════════════════════ --}}
    @php
        // ATÓMICO (ver "cómo funciona"): CMS manda sólo si aporta items reales.
        $lpModCms = ! empty($features['is_active']) && $features['items']->isNotEmpty();
        $lpModTitle = $lpModCms ? (optional($features['section'])->section_title ?: __('landing_prime.modules_title')) : __('landing_prime.modules_title');
        $lpModLead  = $lpModCms ? (optional($features['section'])->section_subtitle ?: __('landing_prime.modules_lead')) : __('landing_prime.modules_lead');
    @endphp
    <section id="solutions" class="bg-white py-20 sm:py-28 px-5 sm:px-6">
        <div class="max-w-6xl mx-auto">
            <header class="max-w-2xl mb-14 lp-reveal">
                <h2 class="text-3xl sm:text-4xl font-bold tracking-tight text-slate-950 mb-3">{{ $lpModTitle }}</h2>
                <p class="text-lg text-slate-600">{{ $lpModLead }}</p>
            </header>

            @if($lpModCms)
                {{-- Contenido del CMS --}}
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
                    @foreach($features['items'] as $feature)
                        <article class="lp-card lp-card--hover rounded-2xl border border-slate-200 bg-white p-6">
                            <div class="w-11 h-11 rounded-xl bg-indigo-50 text-indigo-600 grid place-items-center mb-4">
                                @if($feature->image)
                                    <img src="{{ asset($feature->image) }}" alt="" class="w-6 h-6 object-contain">
                                @else
                                    <i class="{{ $feature->icon ?: 'bi bi-grid' }} text-lg"></i>
                                @endif
                            </div>
                            <h3 class="font-bold text-slate-950 mb-1.5">{{ $feature->title }}</h3>
                            @if($feature->description)<p class="text-sm text-slate-600 leading-relaxed">{{ $feature->description }}</p>@endif
                        </article>
                    @endforeach
                </div>
            @else
                {{-- Fallback editorial: índice de capacidades, no muro de tarjetas. --}}
                <div class="grid md:grid-cols-2 gap-x-12 gap-y-10">
                    @foreach([
                        ['sales', 'bi-receipt'], ['inventory', 'bi-box-seam'], ['operations', 'bi-truck'],
                        ['team', 'bi-people'], ['insights', 'bi-graph-up-arrow'], ['commerce', 'bi-bag-check'],
                    ] as $i => [$m, $icon])
                        <div class="lp-idx lp-reveal" @if($i % 2) data-delay="1" @endif>
                            <h3 class="flex items-center gap-2.5 text-lg font-bold text-slate-950 mb-2">
                                <i class="bi {{ $icon }} lp-idx__mark"></i>{{ __('landing_prime.modules_' . $m . '_title') }}
                            </h3>
                            <p class="text-slate-600 leading-relaxed">{{ __('landing_prime.modules_' . $m . '_desc') }}</p>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    {{-- ═══════════════════════ PRODUCT SHOWCASE ═══════════════════════ --}}
    @include('central.partials.prime.showcase')

    {{-- ═══════════════════════ CÓMO FUNCIONA ═══════════════════════ --}}
    @php
        // i18n ATÓMICO — la sección se resuelve SIEMPRE desde landing_prime.*
        // (deck completo es/en). NO se lee el CMS `landing_how_it_works_*`: ese
        // contenido es de una sola lengua (columnas base sembradas en inglés por
        // la migración, y LandingCmsController::howItWorksSectionUpdate escribe
        // sólo la columna base — no hay ruta de traducción por locale). En un
        // sitio es-first eso producía encabezado en inglés con items en español.
        // La landing legada sigue usando ese CMS; landing-prime, no.
        $lpStepRows = collect([1, 2, 3])->map(fn ($n) => [
            'title' => __('landing_prime.hiw_step' . $n . '_title'),
            'desc'  => __('landing_prime.hiw_step' . $n . '_desc'),
        ]);
    @endphp
    <section id="how-it-works" class="lp-soft py-20 sm:py-28 px-5 sm:px-6">
        <div class="max-w-5xl mx-auto">
            <header class="max-w-2xl mb-14 lp-reveal">
                <h2 class="text-3xl sm:text-4xl font-bold tracking-tight text-slate-950 mb-3">{{ __('landing_prime.hiw_title') }}</h2>
                <p class="text-lg text-slate-600">{{ __('landing_prime.hiw_lead') }}</p>
            </header>
            <ol class="grid md:grid-cols-3 gap-x-8 gap-y-10">
                @foreach($lpStepRows as $i => $row)
                    <li class="relative pl-11 lp-reveal" @if($i) data-delay="{{ $i }}" @endif>
                        <span class="absolute left-0 top-0 w-8 h-8 rounded-full bg-white border border-slate-200 grid place-items-center text-sm font-bold text-indigo-600 lp-tnum">{{ $i + 1 }}</span>
                        <h3 class="font-bold text-slate-950 mb-1.5">{{ $row['title'] }}</h3>
                        <p class="text-slate-600 leading-relaxed">{{ $row['desc'] }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- ═══════════════════════ MULTISUCURSAL ═══════════════════════ --}}
    <section class="bg-white py-20 sm:py-28 px-5 sm:px-6">
        <div class="max-w-6xl mx-auto grid lg:grid-cols-2 gap-12 lg:gap-16 items-center">
            <div class="lp-reveal">
                <h2 class="text-3xl sm:text-4xl font-bold tracking-tight text-slate-950 mb-3">{{ __('landing_prime.multibranch_title') }}</h2>
                <p class="text-lg text-slate-600 mb-8">{{ __('landing_prime.multibranch_lead') }}</p>
                <div class="space-y-5">
                    @foreach([1, 2, 3, 4] as $n)
                        <div class="flex gap-3">
                            <i class="bi bi-check-circle-fill text-emerald-500 mt-0.5"></i>
                            <div>
                                <p class="font-semibold text-slate-900">{{ __('landing_prime.multibranch_point' . $n . '_title') }}</p>
                                <p class="text-sm text-slate-600">{{ __('landing_prime.multibranch_point' . $n . '_desc') }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="lp-window lp-reveal" data-delay="1">
                <div class="lp-window__bar"><span class="lp-window__dot"></span><span class="lp-window__dot"></span><span class="lp-window__dot"></span><span class="lp-window__title">{{ __('landing_prime.value_branches') }}</span></div>
                <div class="p-6 bg-white grid sm:grid-cols-2 gap-4">
                    @foreach([1, 2, 3, 4] as $b)
                        <div class="rounded-xl border border-slate-200 p-4">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="w-2 h-2 rounded-full bg-indigo-400"></span>
                                <span class="h-2.5 rounded bg-slate-100 flex-1"></span>
                            </div>
                            <span class="block h-2 rounded bg-slate-100 mb-1.5" style="width:80%"></span>
                            <span class="block h-2 rounded bg-slate-100" style="width:55%"></span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════════════════ REPORTES ═══════════════════════ --}}
    <section class="lp-soft py-20 sm:py-28 px-5 sm:px-6">
        <div class="max-w-6xl mx-auto grid lg:grid-cols-2 gap-12 lg:gap-16 items-center">
            <div class="lp-window lp-reveal order-2 lg:order-1">
                <div class="lp-window__bar"><span class="lp-window__dot"></span><span class="lp-window__dot"></span><span class="lp-window__dot"></span><span class="lp-window__title">{{ __('landing_prime.reports_window') }}</span></div>
                <div class="p-6 bg-white">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 mb-3">{{ __('landing_prime.reports_chart_caption') }}</p>
                    <div class="lp-bars" aria-hidden="true">
                        <span style="height:38%"></span><span style="height:62%"></span><span style="height:48%"></span>
                        <span style="height:75%"></span><span style="height:58%"></span><span style="height:85%"></span>
                        <span style="height:66%"></span><span style="height:92%"></span>
                    </div>
                </div>
            </div>
            <div class="lp-reveal order-1 lg:order-2" data-delay="1">
                <h2 class="text-3xl sm:text-4xl font-bold tracking-tight text-slate-950 mb-3">{{ __('landing_prime.reports_title') }}</h2>
                <p class="text-lg text-slate-600 mb-6">{{ __('landing_prime.reports_lead') }}</p>
                <ul class="space-y-3">
                    @foreach(['reports_item1', 'reports_item2', 'reports_item3', 'reports_item4'] as $item)
                        <li class="flex items-start gap-2.5 text-sm text-slate-700"><i class="bi bi-check-circle-fill text-emerald-500 mt-0.5"></i>{{ __('landing_prime.' . $item) }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </section>

    {{-- ═══════════════════════ TESTIMONIOS (solo si existen) ═══════════════════════ --}}
    @if($lpTestimonials->isNotEmpty())
    <section id="testimonials" class="bg-white py-20 sm:py-28 px-5 sm:px-6">
        <div class="max-w-6xl mx-auto">
            <header class="max-w-2xl mb-14 lp-reveal">
                <h2 class="text-3xl sm:text-4xl font-bold tracking-tight text-slate-950">{{ __('landing_prime.testimonials_title') }}</h2>
            </header>
            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-5">
                @foreach($lpTestimonials as $t)
                    <figure class="lp-card rounded-2xl border border-slate-200 bg-white p-6 flex flex-col">
                        @if($t->rating)
                            <div class="flex gap-0.5 text-amber-400 mb-3" aria-label="{{ $t->rating }} / 5">
                                @for($s = 1; $s <= 5; $s++)<i class="bi bi-star{{ $s <= $t->rating ? '-fill' : '' }}"></i>@endfor
                            </div>
                        @endif
                        <blockquote class="text-sm text-slate-700 leading-relaxed flex-1">&ldquo;{{ $t->review }}&rdquo;</blockquote>
                        <figcaption class="mt-4 flex items-center gap-3">
                            <span class="w-9 h-9 rounded-full bg-slate-100 grid place-items-center text-slate-500 font-semibold overflow-hidden">
                                @if($t->avatar)<img src="{{ asset($t->avatar) }}" alt="" class="w-full h-full object-cover">@else{{ strtoupper(mb_substr($t->client_name, 0, 1)) }}@endif
                            </span>
                            <span>
                                <span class="block text-sm font-semibold text-slate-900">{{ $t->client_name }}</span>
                                @if($t->company_name)<span class="block text-xs text-slate-500">{{ $t->company_name }}</span>@endif
                            </span>
                        </figcaption>
                    </figure>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    {{-- ═══════════════════════ FAQ (solo si existen) ═══════════════════════ --}}
    @if($lpFaqs->isNotEmpty())
    <section id="faq" class="lp-soft py-20 sm:py-28 px-5 sm:px-6">
        <div class="max-w-3xl mx-auto">
            <header class="mb-10 lp-reveal">
                <h2 class="text-3xl sm:text-4xl font-bold tracking-tight text-slate-950">{{ __('landing.faq_title') }}</h2>
            </header>
            <div class="divide-y divide-slate-200 border-y border-slate-200">
                @foreach($lpFaqs as $faq)
                    <details class="group py-1">
                        <summary class="flex items-center justify-between gap-4 cursor-pointer font-semibold text-slate-900 list-none py-4 rounded-lg">
                            {{ $faq->question }}
                            <i class="bi bi-plus-lg text-slate-400 group-open:rotate-45 transition-transform shrink-0"></i>
                        </summary>
                        <div class="pb-4 -mt-1 text-slate-600 leading-relaxed">{!! nl2br(e($faq->answer)) !!}</div>
                    </details>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    {{-- ═══════════════════════ CTA FINAL — cierre de marca ═══════════════════════ --}}
    @if($lpCtaVisible || ! $cta)
    <section class="bg-white pt-10 pb-20 sm:pt-14 sm:pb-28 px-5 sm:px-6">
        <div class="lp-deep max-w-5xl mx-auto rounded-[2rem] px-6 sm:px-16 py-20 sm:py-24 text-center lp-reveal">
            <span class="lp-mark lp-mark--center mb-7"></span>
            <h2 class="text-3xl sm:text-[2.75rem] font-bold tracking-[-0.03em] leading-[1.08] mb-4 text-white text-balance">{{ optional($cta)->title ?: __('landing_prime.cta_title') }}</h2>
            <p class="text-slate-300/90 text-lg max-w-xl mx-auto mb-9">{{ optional($cta)->subtitle ?: __('landing_prime.cta_lead') }}</p>
            <div class="flex flex-col sm:flex-row gap-3 justify-center">
                <a href="{{ optional($cta)->button_url ?: $lpRegisterUrl }}" class="lp-btn lp-btn--lg bg-white text-slate-950 hover:bg-slate-100">
                    {{ optional($cta)->button_text ?: __('landing_prime.cta_button') }} <i class="bi bi-arrow-right"></i>
                </a>
                <a href="{{ $lpSalesHref }}" @if($lpSalesExternal) target="_blank" rel="noopener noreferrer" @endif class="lp-btn lp-btn--lg lp-btn--ghost">
                    <i class="bi bi-chat-dots"></i> {{ optional($cta)->sales_button_text ?: __('landing_prime.cta_sales') }}
                </a>
            </div>
        </div>
    </section>
    @endif
</main>

{{-- ═══════════════════════ FOOTER ═══════════════════════ --}}
<footer class="bg-white border-t border-slate-200 px-5 sm:px-6 py-14">
    <div class="max-w-6xl mx-auto grid gap-10 md:grid-cols-[1.4fr_1fr_1fr_1fr]">
        <div>
            @if($generalSettings->show_site_name ?? true)
                <a href="{{ route('central.welcome') }}" class="font-bold text-slate-950 text-lg">{{ $appName }}</a>
            @endif
            <p class="mt-3 text-sm text-slate-500 max-w-xs">{{ optional($footer)->footer_about ?: __('landing_prime.footer_tagline') }}</p>
            @if($footer)
                <div class="mt-4 flex gap-2">
                    @foreach(['facebook' => 'bi-facebook', 'twitter' => 'bi-twitter-x', 'linkedin' => 'bi-linkedin', 'instagram' => 'bi-instagram', 'youtube' => 'bi-youtube'] as $net => $icon)
                        @if($footer->$net)
                            <a href="{{ $footer->$net }}" target="_blank" rel="noopener noreferrer" class="w-9 h-9 grid place-items-center rounded-lg border border-slate-200 text-slate-500 hover:text-slate-900" aria-label="{{ ucfirst($net) }}"><i class="bi {{ $icon }}"></i></a>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>

        <div id="contact-sales">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-400 mb-3">{{ __('landing.contact_us') }}</p>
            <div class="space-y-2 text-sm text-slate-600">
                @if($lpSalesEmail)<a href="mailto:{{ $lpSalesEmail }}" class="flex items-center gap-2 hover:text-slate-900"><i class="bi bi-envelope"></i>{{ $lpSalesEmail }}</a>@endif
                @if($lpSalesWhatsappHref)<a href="{{ $lpSalesWhatsappHref }}" target="_blank" rel="noopener noreferrer" class="flex items-center gap-2 hover:text-slate-900"><i class="bi bi-whatsapp"></i>{{ $lpSalesWhatsappRaw }}</a>
                @elseif(optional($footer)->contact_phone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $footer->contact_phone) }}" class="flex items-center gap-2 hover:text-slate-900"><i class="bi bi-telephone"></i>{{ $footer->contact_phone }}</a>@endif
                @if(optional($footer)->address)<p class="flex items-start gap-2"><i class="bi bi-geo-alt mt-0.5"></i>{{ $footer->address }}</p>@endif
            </div>
        </div>

        <div>
            <p class="text-xs font-bold uppercase tracking-wide text-slate-400 mb-3">{{ __('landing_prime.footer_product') }}</p>
            <nav class="flex flex-col gap-2 text-sm text-slate-600">
                <a href="#product" class="hover:text-slate-900">{{ __('landing_prime.nav_product') }}</a>
                <a href="#pricing" class="hover:text-slate-900">{{ __('landing_prime.nav_pricing') }}</a>
                <a href="#how-it-works" class="hover:text-slate-900">{{ __('landing.how_it_works') }}</a>
                @if($lpFaqs->isNotEmpty())<a href="#faq" class="hover:text-slate-900">{{ __('landing.faq') }}</a>@endif
            </nav>
        </div>

        <div>
            <p class="text-xs font-bold uppercase tracking-wide text-slate-400 mb-3">{{ __('landing.company') }}</p>
            <nav class="flex flex-col gap-2 text-sm text-slate-600">
                <a href="{{ route('central.welcome') }}" class="hover:text-slate-900">{{ __('landing.home') }}</a>
                <a href="{{ $lpRegisterUrl }}" class="hover:text-slate-900">{{ __('landing.sign_up') }}</a>
                <a href="{{ url('/sistema-pos-honduras') }}" class="hover:text-slate-900">Sistema POS</a>
                <a href="{{ url('/software-inventario-honduras') }}" class="hover:text-slate-900">Control de inventario</a>
                <a href="{{ url('/erp-honduras') }}" class="hover:text-slate-900">ERP para empresas</a>
                <a href="{{ route('central.privacy-policy') }}" class="hover:text-slate-900">{{ __('landing.privacy_policy') }}</a>
                <a href="{{ route('central.terms-conditions') }}" class="hover:text-slate-900">{{ __('landing.terms_and_conditions') }}</a>
                <a href="#" id="lpCookiePrefs" class="hover:text-slate-900">{{ __('landing.cookie_preferences_link') }}</a>
            </nav>
        </div>
    </div>
    <div class="max-w-6xl mx-auto mt-10 pt-6 border-t border-slate-100 text-xs text-slate-400">
        {{ optional($footer)->copyright_text ?: '© ' . date('Y') . ' ' . $appName . '. ' . __('landing.all_rights') }}
    </div>
</footer>

@if($footer && ($footer->show_sales_floating_button ?? true) && $lpSalesWhatsappHref)
    <a class="lp-fab" href="{{ $lpSalesWhatsappHref }}" target="_blank" rel="noopener noreferrer" aria-label="{{ __('landing.talk_to_sales') }}">
        <i class="bi bi-whatsapp text-lg"></i><span>{{ __('landing.talk_to_sales') }}</span>
    </a>
@endif

{{-- Cookie consent --}}
<div id="lpCookie" class="lp-cookie fixed z-[50] left-4 right-4 sm:left-auto sm:right-6 bottom-6 sm:max-w-sm bg-white border border-slate-200 rounded-2xl shadow-2xl p-5" data-hidden="true">
    <div class="flex items-center gap-2 mb-2"><i class="bi bi-shield-lock text-indigo-600"></i><p class="font-bold text-slate-950 text-sm">{{ __('landing.cookie_banner_title') }}</p></div>
    <p class="text-xs text-slate-600 leading-relaxed">{{ __('landing.cookie_banner_text') }} <a href="{{ route('central.privacy-policy') }}#cookies" class="text-indigo-600">{{ __('landing.privacy_policy') }}</a></p>
    <div class="mt-3 flex flex-wrap gap-2">
        <button id="lpCookieAccept" class="lp-btn lp-btn--primary text-xs px-3 py-2">{{ __('landing.cookie_accept_all') }}</button>
        <button id="lpCookieReject" class="lp-btn lp-btn--ghost text-xs px-3 py-2">{{ __('landing.cookie_reject_all') }}</button>
        <button id="lpCookieCustomize" class="lp-btn lp-btn--ghost text-xs px-3 py-2">{{ __('landing.cookie_customize') }}</button>
    </div>
    <div id="lpCookiePanel" hidden class="mt-3 space-y-2 border-t border-slate-100 pt-3">
        <label class="flex items-center justify-between text-xs text-slate-600"><span>{{ __('landing.cookie_necessary') }}</span><input type="checkbox" checked disabled></label>
        <label class="flex items-center justify-between text-xs text-slate-600"><span>{{ __('landing.cookie_analytics') }}</span><input type="checkbox" id="lpCookieAnalytics"></label>
        <label class="flex items-center justify-between text-xs text-slate-600"><span>{{ __('landing.cookie_marketing') }}</span><input type="checkbox" id="lpCookieMarketing"></label>
        <button id="lpCookieSave" class="lp-btn lp-btn--ghost text-xs px-3 py-2 w-full">{{ __('landing.cookie_save_preferences') }}</button>
    </div>
</div>

<script src="{{ asset('assets_super/js/landing-prime.js') }}" defer></script>
</body>
</html>
