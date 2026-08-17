<!DOCTYPE html>
@php
    $isRtl = in_array(app()->getLocale(), ['ar', 'he', 'fa', 'ur']);
    $generalSettings = \App\Models\Central\GeneralSetting::instance();
    $appName = $generalSettings->app_name ?: 'Stocky';
    $logoUrl = $generalSettings->getLogoUrl();

    $l3HeroVisible = $hero && ($hero->is_active ?? true);
    $l3CtaVisible = $cta && ($cta->is_active ?? true) && ($cta->show_commercial_cta ?? true);

    $l3SalesEmail = $footer->sales_email ?? $footer->contact_email ?? null;
    $l3SalesWhatsappRaw = $footer->sales_whatsapp_number ?? $footer->contact_phone ?? null;
    $l3SalesWhatsappNumber = $l3SalesWhatsappRaw ? preg_replace('/\D+/', '', $l3SalesWhatsappRaw) : null;
    $l3SalesWhatsappMessage = ($footer->sales_whatsapp_message ?? null)
        ?: 'Hola, me interesa conocer más sobre Prodex y sus planes.';
    $l3SalesWhatsappHref = $l3SalesWhatsappNumber
        ? 'https://wa.me/' . $l3SalesWhatsappNumber . '?text=' . rawurlencode($l3SalesWhatsappMessage)
        : null;
    $l3SalesEmailHref = $l3SalesEmail
        ? 'mailto:' . $l3SalesEmail . '?subject=' . rawurlencode('Consulta comercial Prodex')
        : null;
    $l3SalesContactHref = $cta->sales_button_url ?? null;
    $l3SalesContactHref = $l3SalesContactHref ?: ($l3SalesWhatsappHref ?: ($l3SalesEmailHref ?: '#contact-sales'));
    $l3SalesContactExternal = $l3SalesWhatsappHref && $l3SalesContactHref === $l3SalesWhatsappHref;

    $l3Faqs = isset($faqs)
        ? $faqs->filter(fn ($f) => ($f->is_active ?? true))
        : collect();

    $l3TestimonialsActive = isset($testimonials)
        ? $testimonials->filter(fn ($t) => ($t->is_active ?? true))
        : collect();

    $l3FeatureItems = $features['items'] ?? collect();

    $l3PlatformVisible =
        !empty($features['is_active']) &&
        ($l3FeatureItems->isNotEmpty() || ($features['section'] ?? null));

    $l3Plans = $pricing['plans'] ?? collect();

    $pricingHasYearly = $l3Plans->isNotEmpty() &&
        $l3Plans->contains(fn ($p) => ($p->yearly_price ?? 0) > 0);
@endphp

<html lang="{{ app()->getLocale() }}"
      class="scroll-smooth"
      @if($isRtl) dir="rtl" @endif>

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
    <link href="{{ asset('assets_super/css/landing-three-page.css') }}" rel="stylesheet">

    @include('central.partials.landing-font')

    <style>
        :root {
            --l3-primary: #4f46e5;
            --l3-primary-dark: #3730a3;
            --l3-dark: #0f172a;
            --l3-muted: #64748b;
            --l3-border: #e2e8f0;
            --l3-bg: #f8fafc;
        }

        body {
            background: #f8fafc;
        }

        .l3-grid {
            background-image:
                linear-gradient(rgba(148,163,184,.10) 1px, transparent 1px),
                linear-gradient(90deg, rgba(148,163,184,.10) 1px, transparent 1px);
            background-size: 42px 42px;
        }

        .l3-glow {
            filter: blur(90px);
        }

        .l3-card {
            transition:
                transform .3s ease,
                box-shadow .3s ease,
                border-color .3s ease;
        }

        .l3-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 45px rgba(15, 23, 42, .09);
            border-color: rgba(99, 102, 241, .25);
        }

        .l3-dashboard {
            box-shadow:
                0 40px 80px rgba(15, 23, 42, .18),
                0 10px 30px rgba(79, 70, 229, .08);
        }

        .l3-gradient-text {
            background: linear-gradient(90deg, #4f46e5, #2563eb, #06b6d4);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .l3-soft-gradient {
            background:
                radial-gradient(circle at 20% 10%, rgba(99,102,241,.14), transparent 30%),
                radial-gradient(circle at 80% 20%, rgba(14,165,233,.12), transparent 30%),
                #f8fafc;
        }

        .l3-number {
            font-variant-numeric: tabular-nums;
        }

        .l3-marquee {
            mask-image: linear-gradient(
                to right,
                transparent,
                black 10%,
                black 90%,
                transparent
            );
        }

        /* =====================================================
           PLANES DE PRECIOS
        ===================================================== */

        .l3-pricing-grid {
            align-items: stretch;
        }

        .l3-pricing-card {
            position: relative;
            display: flex;
            flex-direction: column;
            padding: 1.5rem;
            border-radius: 1.25rem;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            min-height: 100%;
            transition:
                transform .25s ease,
                box-shadow .25s ease,
                border-color .25s ease;
        }

        .l3-pricing-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 18px 40px rgba(15, 23, 42, .09);
            border-color: rgba(99, 102, 241, .30);
        }

        .l3-pricing-card.featured {
            border-color: #6366f1;
            box-shadow: 0 14px 35px rgba(79, 70, 229, .10);
        }

        .l3-pricing-card .l3-plan-description {
            min-height: 34px;
        }

        .l3-pricing-card .l3-plan-price-area {
            margin-top: 1rem;
            margin-bottom: 1rem;
        }

        .l3-pricing-card .l3-plan-button {
            margin-top: .25rem;
            margin-bottom: 1.25rem;
        }

        .l3-pricing-card .l3-plan-included {
            padding-top: 1rem;
            border-top: 1px solid #f1f5f9;
        }

        .l3-pricing-card .l3-feature-list {
            max-height: 210px;
            overflow-y: auto;
            padding-right: 2px;
        }

        .l3-pricing-card .l3-feature-list::-webkit-scrollbar {
            width: 3px;
        }

        .l3-pricing-card .l3-feature-list::-webkit-scrollbar-track {
            background: transparent;
        }

        .l3-pricing-card .l3-feature-list::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 999px;
        }

        @media (max-width: 767px) {
            .l3-pricing-card {
                padding: 1.35rem;
            }

            .l3-pricing-card .l3-feature-list {
                max-height: none;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                scroll-behavior: auto !important;
                animation-duration: .01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: .01ms !important;
            }
        }
    </style>
</head>

<body class="bg-slate-50 text-slate-900 antialiased overflow-x-hidden">

    {{-- =========================================================
         FONDO
    ========================================================== --}}
    <div class="fixed inset-0 -z-10 pointer-events-none overflow-hidden">
        <div class="absolute -top-40 -left-40 w-[600px] h-[600px] rounded-full bg-indigo-400/10 l3-glow"></div>
        <div class="absolute top-20 -right-40 w-[600px] h-[600px] rounded-full bg-cyan-400/10 l3-glow"></div>
    </div>

    {{-- =========================================================
         NAVBAR
    ========================================================== --}}
    <header
        id="l3Header"
        class="fixed top-0 inset-x-0 z-50 bg-white/80 backdrop-blur-xl border-b border-slate-200/70">

        <div class="max-w-7xl mx-auto px-5 sm:px-6 h-[72px] flex items-center justify-between gap-5">

            <a href="{{ route('central.welcome') }}"
               class="flex items-center gap-3 shrink-0">

                <span class="w-9 h-9 rounded-xl bg-slate-950 text-white flex items-center justify-center overflow-hidden shadow-sm">
                    @if($logoUrl)
                        <img
                            src="{{ $logoUrl }}"
                            alt=""
                            class="w-full h-full object-cover">
                    @else
                        <iconify-icon
                            icon="solar:box-minimalistic-linear"
                            class="text-xl">
                        </iconify-icon>
                    @endif
                </span>

                @if($generalSettings->show_site_name ?? true)
                    <span class="text-lg font-bold tracking-tight text-slate-950">
                        {{ $appName }}
                    </span>
                @endif
            </a>

            <nav
                class="hidden lg:flex items-center gap-7 text-sm font-medium text-slate-500"
                aria-label="Navegación principal">

                @if(!empty($features['is_active']))
                    <a href="#features" class="hover:text-slate-950 transition">
                        Características
                    </a>
                @endif

                @if($l3PlatformVisible)
                    <a href="#platform" class="hover:text-slate-950 transition">
                        Plataforma
                    </a>
                @endif

                @if(!empty($pricing['is_active']))
                    <a href="#pricing" class="hover:text-slate-950 transition">
                        Precios
                    </a>
                @endif

                @if(!empty($howItWorks['is_active']))
                    <a href="#journey" class="hover:text-slate-950 transition">
                        Cómo funciona
                    </a>
                @endif

                @if($l3TestimonialsActive->isNotEmpty())
                    <a href="#testimonials" class="hover:text-slate-950 transition">
                        Opiniones
                    </a>
                @endif

                @if($l3Faqs->isNotEmpty())
                    <a href="#faq" class="hover:text-slate-950 transition">
                        Preguntas frecuentes
                    </a>
                @endif
            </nav>

            <div class="flex items-center gap-2">

                @if(isset($languages) && $languages->count() > 1)
                    <div class="relative hidden sm:block" id="l3Lang">

                        <button
                            type="button"
                            id="l3LangBtn"
                            aria-expanded="false"
                            aria-haspopup="true"
                            class="flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-semibold text-slate-600 hover:bg-slate-100 transition">

                            <i class="bi bi-globe2"></i>

                            {{ strtoupper($currentLocale ?? app()->getLocale()) }}

                            <i class="bi bi-chevron-down text-[9px]"></i>
                        </button>

                        <div
                            id="l3LangMenu"
                            role="menu"
                            class="hidden absolute end-0 mt-2 min-w-[160px] overflow-hidden rounded-2xl bg-white border border-slate-200 shadow-xl">

                            @foreach($languages as $lang)
                                <form method="POST"
                                      action="{{ route('central.locale', $lang->locale) }}">

                                    @csrf

                                    <button
                                        type="submit"
                                        class="w-full text-start px-4 py-3 text-sm text-slate-700 hover:bg-slate-50 flex items-center gap-3">

                                        @if($lang->flag)
                                            <img
                                                src="{{ asset('flags/' . $lang->flag) }}"
                                                alt=""
                                                class="w-5 rounded-sm">
                                        @endif

                                        {{ $lang->name }}
                                    </button>
                                </form>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if($footer && ($footer->show_admin_login ?? false))
                    <a
                        href="{{ route('central.login') }}"
                        class="hidden sm:inline px-3 py-2 text-sm font-medium text-slate-600 hover:text-slate-950 transition">
                        Iniciar sesión
                    </a>
                @endif

                <a
                    href="{{ route('central.register') }}"
                    class="hidden sm:inline-flex items-center gap-2 px-5 py-2.5 rounded-full bg-slate-950 text-white text-sm font-semibold hover:bg-indigo-600 transition shadow-lg shadow-slate-900/10">

                    Registrarse gratis

                    <iconify-icon icon="solar:arrow-right-linear"></iconify-icon>
                </a>

                <button
                    type="button"
                    id="l3OpenMenu"
                    aria-expanded="false"
                    aria-controls="l3Drawer"
                    aria-label="Abrir menú"
                    class="lg:hidden w-10 h-10 rounded-xl flex items-center justify-center hover:bg-slate-100">

                    <i class="bi bi-list text-xl"></i>
                </button>

            </div>
        </div>
    </header>

    {{-- =========================================================
         MENÚ MÓVIL
    ========================================================== --}}
    <div
        id="l3Drawer"
        class="fixed inset-0 z-[60] hidden lg:hidden"
        aria-hidden="true">

        <div
            class="absolute inset-0 bg-slate-950/40 backdrop-blur-sm"
            data-l3-drawer-backdrop>
        </div>

        <div
            id="l3DrawerPanel"
            class="absolute top-0 end-0 h-full w-[min(21rem,100%)] bg-white shadow-2xl p-6 flex flex-col"
            role="dialog"
            aria-modal="true"
            aria-label="Menú">

            <div class="flex justify-between items-center mb-8">

                <span class="font-bold text-lg">
                    {{ $appName }}
                </span>

                <button
                    type="button"
                    id="l3CloseMenu"
                    aria-label="Cerrar"
                    class="w-10 h-10 rounded-xl hover:bg-slate-100 flex items-center justify-center">

                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <nav class="flex flex-col gap-1 text-base font-medium">

                @if(!empty($features['is_active']))
                    <a href="#features" class="px-4 py-3 rounded-xl hover:bg-slate-50">
                        Características
                    </a>
                @endif

                @if($l3PlatformVisible)
                    <a href="#platform" class="px-4 py-3 rounded-xl hover:bg-slate-50">
                        Plataforma
                    </a>
                @endif

                @if(!empty($pricing['is_active']))
                    <a href="#pricing" class="px-4 py-3 rounded-xl hover:bg-slate-50">
                        Precios
                    </a>
                @endif

                @if(!empty($howItWorks['is_active']))
                    <a href="#journey" class="px-4 py-3 rounded-xl hover:bg-slate-50">
                        Cómo funciona
                    </a>
                @endif

                @if($l3TestimonialsActive->isNotEmpty())
                    <a href="#testimonials" class="px-4 py-3 rounded-xl hover:bg-slate-50">
                        Opiniones
                    </a>
                @endif

                @if($l3Faqs->isNotEmpty())
                    <a href="#faq" class="px-4 py-3 rounded-xl hover:bg-slate-50">
                        Preguntas frecuentes
                    </a>
                @endif

            </nav>

            <div class="mt-auto pt-6 border-t border-slate-100 flex flex-col gap-3">

                @if($footer && ($footer->show_admin_login ?? false))
                    <a
                        href="{{ route('central.login') }}"
                        class="w-full text-center py-3.5 rounded-xl border border-slate-200 font-semibold">
                        Iniciar sesión
                    </a>
                @endif

                <a
                    href="{{ route('central.register') }}"
                    class="w-full text-center py-3.5 rounded-xl bg-slate-950 text-white font-semibold">
                    Registrarse gratis
                </a>

            </div>
        </div>
    </div>

    <main>

        {{-- =====================================================
             HERO
        ====================================================== --}}
        @if($l3HeroVisible)

            <section
                id="top"
                aria-labelledby="l3-hero-title"
                class="relative pt-36 pb-20 md:pt-44 md:pb-28 px-5 sm:px-6 overflow-hidden l3-soft-gradient">

                <div class="absolute inset-0 l3-grid opacity-40 pointer-events-none"></div>

                @if($hero->background_image)
                    <div
                        class="absolute inset-0 bg-cover bg-center opacity-10"
                        style="background-image:url('{{ asset($hero->background_image) }}')">
                    </div>
                @endif

                <div class="relative max-w-7xl mx-auto">

                    <div class="max-w-4xl mx-auto text-center">

                        <div class="inline-flex items-center gap-2 px-3.5 py-2 rounded-full bg-white border border-indigo-100 shadow-sm text-xs font-semibold text-indigo-700 mb-7">

                            <span class="w-2 h-2 rounded-full bg-indigo-500"></span>

                            {{ $hero->subtitle ?? ($appName . ' — comienza gratis') }}

                        </div>

                        <h1
                            id="l3-hero-title"
                            class="text-5xl sm:text-6xl md:text-7xl font-bold tracking-[-0.05em] leading-[1.02] text-slate-950 mb-7">

                            @if($hero->title)
                                {!! $hero->title !!}
                            @else
                                Lleva tu negocio al siguiente nivel con

                                <span class="l3-gradient-text">
                                    {{ $appName }}
                                </span>
                            @endif

                        </h1>

                        <p class="max-w-2xl mx-auto text-lg md:text-xl leading-relaxed text-slate-600 mb-9">

                            {{ $hero->description ?? 'Administra tu inventario, ventas y operaciones desde una sola plataforma.' }}

                        </p>

                        <div class="flex flex-col sm:flex-row justify-center items-center gap-3">

                            <a
                                href="{{ $hero->primary_button_url ?? route('central.register') }}"
                                class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-7 py-3.5 rounded-full bg-slate-950 text-white font-semibold hover:bg-indigo-600 transition shadow-xl shadow-slate-900/15">

                                {{ $hero->primary_button_text ?? 'Comenzar gratis' }}

                                <iconify-icon
                                    icon="solar:arrow-right-linear"
                                    class="text-lg">
                                </iconify-icon>
                            </a>

                            <a
                                href="{{ $hero->secondary_button_url ?? ($l3PlatformVisible ? '#platform' : '#features') }}"
                                class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-7 py-3.5 rounded-full bg-white border border-slate-200 text-slate-700 font-semibold hover:border-slate-300 hover:bg-slate-50 transition">

                                <iconify-icon icon="solar:play-circle-linear"></iconify-icon>

                                Ver cómo funciona

                            </a>

                        </div>

                        <div class="mt-7 flex flex-wrap justify-center gap-x-6 gap-y-2 text-xs font-medium text-slate-500">

                            <span class="flex items-center gap-1.5">
                                <i class="bi bi-check-circle-fill text-emerald-500"></i>
                                Inventario
                            </span>

                            <span class="flex items-center gap-1.5">
                                <i class="bi bi-check-circle-fill text-emerald-500"></i>
                                Punto de venta
                            </span>

                            <span class="flex items-center gap-1.5">
                                <i class="bi bi-check-circle-fill text-emerald-500"></i>
                                Reportes
                            </span>

                            <span class="flex items-center gap-1.5">
                                <i class="bi bi-check-circle-fill text-emerald-500"></i>
                                Múltiples ubicaciones
                            </span>

                        </div>

                    </div>

                    {{-- VISTA DEL PANEL --}}
                    <div class="max-w-6xl mx-auto mt-16 md:mt-20">

                        <div class="relative">

                            <div class="absolute -inset-8 bg-indigo-500/10 blur-3xl rounded-full"></div>

                            <div class="relative l3-dashboard rounded-2xl md:rounded-3xl border border-slate-200 bg-white overflow-hidden">

                                <div class="h-11 border-b border-slate-200 bg-slate-50 flex items-center px-4 gap-2">

                                    <span class="w-2.5 h-2.5 rounded-full bg-slate-300"></span>
                                    <span class="w-2.5 h-2.5 rounded-full bg-slate-300"></span>
                                    <span class="w-2.5 h-2.5 rounded-full bg-slate-300"></span>

                                    <div class="ml-4 h-6 max-w-xs flex-1 rounded-md bg-white border border-slate-200"></div>

                                </div>

                                @if($hero->hero_image)

                                    <img
                                        src="{{ asset($hero->hero_image) }}"
                                        alt="{{ strip_tags($hero->title ?? $appName) }}"
                                        class="w-full h-auto block"
                                        loading="eager"
                                        decoding="async">

                                @else

                                    <div class="p-5 md:p-8">

                                        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 md:gap-5">

                                            <div class="rounded-2xl bg-slate-50 border border-slate-200 p-4">
                                                <div class="text-xs text-slate-500 mb-2">Ingresos</div>
                                                <div class="text-xl md:text-2xl font-bold">$24,580</div>
                                                <div class="text-xs text-emerald-600 mt-1">+18.4%</div>
                                            </div>

                                            <div class="rounded-2xl bg-slate-50 border border-slate-200 p-4">
                                                <div class="text-xs text-slate-500 mb-2">Productos</div>
                                                <div class="text-xl md:text-2xl font-bold">1,284</div>
                                                <div class="text-xs text-slate-500 mt-1">En inventario</div>
                                            </div>

                                            <div class="rounded-2xl bg-slate-50 border border-slate-200 p-4">
                                                <div class="text-xs text-slate-500 mb-2">Pedidos</div>
                                                <div class="text-xl md:text-2xl font-bold">856</div>
                                                <div class="text-xs text-emerald-600 mt-1">+12.7%</div>
                                            </div>

                                            <div class="rounded-2xl bg-slate-50 border border-slate-200 p-4">
                                                <div class="text-xs text-slate-500 mb-2">Stock bajo</div>
                                                <div class="text-xl md:text-2xl font-bold">24</div>
                                                <div class="text-xs text-amber-600 mt-1">Requiere atención</div>
                                            </div>

                                        </div>

                                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mt-5">

                                            <div class="lg:col-span-2 rounded-2xl border border-slate-200 p-5">

                                                <div class="flex justify-between items-center mb-6">
                                                    <div>
                                                        <h3 class="font-semibold text-slate-900">
                                                            Resumen de ventas
                                                        </h3>
                                                        <p class="text-xs text-slate-500 mt-1">
                                                            Rendimiento general
                                                        </p>
                                                    </div>

                                                    <span class="text-xs px-3 py-1.5 rounded-lg bg-slate-50 border border-slate-200">
                                                        Últimos 30 días
                                                    </span>
                                                </div>

                                                <div class="h-44 flex items-end gap-2">

                                                    @foreach([22,35,28,48,42,58,52,67,61,76,70,88,80,94] as $height)
                                                        <div
                                                            class="flex-1 rounded-t-md bg-gradient-to-t from-indigo-600 to-blue-400"
                                                            style="height:{{ $height }}%">
                                                        </div>
                                                    @endforeach

                                                </div>

                                            </div>

                                            <div class="rounded-2xl border border-slate-200 p-5">

                                                <h3 class="font-semibold text-slate-900 mb-5">
                                                    Actividad reciente
                                                </h3>

                                                <div class="space-y-4">

                                                    @foreach([
                                                        ['Venta de producto', '$245.00'],
                                                        ['Entrada de inventario', '+120 unidades'],
                                                        ['Nuevo pedido', '$680.00'],
                                                        ['Actualización de inventario', 'Completada']
                                                    ] as $activity)

                                                        <div class="flex items-center justify-between gap-3">

                                                            <div class="flex items-center gap-3 min-w-0">

                                                                <span class="w-8 h-8 rounded-lg bg-indigo-50 flex items-center justify-center shrink-0">
                                                                    <i class="bi bi-check2 text-indigo-600"></i>
                                                                </span>

                                                                <span class="text-xs text-slate-600 truncate">
                                                                    {{ $activity[0] }}
                                                                </span>

                                                            </div>

                                                            <span class="text-xs font-semibold text-slate-800 shrink-0">
                                                                {{ $activity[1] }}
                                                            </span>

                                                        </div>

                                                    @endforeach

                                                </div>

                                            </div>

                                        </div>

                                    </div>

                                @endif

                            </div>

                        </div>

                    </div>

                    @if(session('success') && session('tenant_url'))

                        <div class="mt-6 p-4 rounded-2xl bg-emerald-50 border border-emerald-100 text-emerald-900 text-sm max-w-xl mx-auto text-center">

                            {{ session('message') }}

                            <br>

                            <a
                                href="{{ session('tenant_url') }}"
                                class="font-semibold underline">
                                {{ session('tenant_url') }}
                            </a>

                        </div>

                    @endif

                </div>
            </section>

        @else

            <div class="pt-24"></div>

        @endif


        {{-- =====================================================
             ESTADÍSTICAS
        ====================================================== --}}
        @if(isset($stats) && $stats->isNotEmpty())

            <section class="bg-white border-y border-slate-200">

                <div class="max-w-7xl mx-auto px-6 py-10">

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-8">

                        @foreach($stats as $stat)

                            <div class="text-center">

                                <div class="text-3xl md:text-4xl font-bold tracking-tight text-slate-950">
                                    {{ $stat->value }}
                                </div>

                                <div class="text-xs font-medium text-slate-500 mt-2">
                                    {{ $stat->label }}
                                </div>

                            </div>

                        @endforeach

                    </div>

                </div>

            </section>

        @endif


        {{-- =====================================================
             CARACTERÍSTICAS
        ====================================================== --}}
        @if(!empty($features['is_active']) && $l3FeatureItems->isNotEmpty())

            <section
                id="features"
                class="py-24 md:py-32 px-6 bg-white scroll-mt-24">

                <div class="max-w-7xl mx-auto">

                    <div class="max-w-2xl mb-16">

                        <span class="text-sm font-semibold text-indigo-600">
                            Características
                        </span>

                        <h2 class="mt-3 text-4xl md:text-5xl font-bold tracking-tight text-slate-950">

                            {{ $features['section']->section_title ?? 'Todo lo que necesitas para administrar tu negocio' }}

                        </h2>

                        <p class="mt-5 text-lg leading-relaxed text-slate-500">

                            {{ $features['section']->section_subtitle ?? 'Herramientas diseñadas para hacer tus operaciones diarias más sencillas, rápidas y organizadas.' }}

                        </p>

                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">

                        @foreach($l3FeatureItems as $i => $feature)

                            <article
                                class="l3-card group rounded-3xl border border-slate-200 bg-white p-7">

                                <div class="flex items-center justify-between mb-12">

                                    <span class="text-xs font-bold text-slate-300">
                                        {{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}
                                    </span>

                                    <div class="w-11 h-11 rounded-xl bg-indigo-50 flex items-center justify-center">

                                        @if($feature->image)

                                            <img
                                                src="{{ asset($feature->image) }}"
                                                alt=""
                                                class="w-6 h-6 object-contain">

                                        @elseif($feature->icon)

                                            <i class="{{ $feature->icon }} text-xl text-indigo-600"></i>

                                        @else

                                            <iconify-icon
                                                icon="solar:box-linear"
                                                class="text-xl text-indigo-600">
                                            </iconify-icon>

                                        @endif

                                    </div>

                                </div>

                                <h3 class="text-xl font-semibold tracking-tight text-slate-950 mb-3">
                                    {{ $feature->title }}
                                </h3>

                                @if($feature->description)

                                    <p class="text-sm leading-7 text-slate-500">
                                        {{ $feature->description }}
                                    </p>

                                @endif

                                <div class="mt-6 text-indigo-600 opacity-0 group-hover:opacity-100 transition">
                                    <iconify-icon icon="solar:arrow-right-linear" class="text-xl"></iconify-icon>
                                </div>

                            </article>

                        @endforeach

                    </div>

                </div>

            </section>

        @endif


        {{-- =====================================================
             PLATAFORMA
        ====================================================== --}}
        @if($l3PlatformVisible)

            @php
                $pf0 = $l3FeatureItems->get(0);
                $pf1 = $l3FeatureItems->get(1);
                $pf2 = $l3FeatureItems->get(2);
                $pf3 = $l3FeatureItems->get(3);
            @endphp

            <section
                id="platform"
                class="py-24 md:py-32 px-6 bg-slate-950 text-white relative overflow-hidden scroll-mt-24">

                <div class="absolute top-0 right-0 w-[500px] h-[500px] rounded-full bg-indigo-600/20 blur-[120px]"></div>
                <div class="absolute bottom-0 left-0 w-[450px] h-[450px] rounded-full bg-blue-600/10 blur-[120px]"></div>

                <div class="max-w-7xl mx-auto relative">

                    <div class="max-w-3xl mb-16">

                        <span class="text-sm font-semibold text-indigo-400">
                            Plataforma
                        </span>

                        <h2 class="mt-3 text-4xl md:text-5xl font-bold tracking-tight">
                            {{ optional($features['section'])->section_title ?? 'Una plataforma para todo tu negocio' }}
                        </h2>

                        <p class="mt-5 text-lg leading-relaxed text-slate-400">
                            {{ optional($features['section'])->section_subtitle ?? 'Administra productos, ventas, inventario y el rendimiento de tu negocio desde un solo lugar.' }}
                        </p>

                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

                        {{-- INVENTARIO --}}
                        <div class="lg:col-span-2 rounded-3xl border border-slate-800 bg-slate-900/70 p-7 md:p-9">

                            <div class="grid lg:grid-cols-2 gap-10 items-center">

                                <div>

                                    <span class="text-xs uppercase tracking-widest text-indigo-400 font-semibold">
                                        Inventario
                                    </span>

                                    <h3 class="text-2xl md:text-3xl font-semibold mt-3 mb-4">
                                        {{ $pf0?->title ?? 'Control total de tu inventario' }}
                                    </h3>

                                    <p class="text-slate-400 leading-7 text-sm">
                                        {{ $pf0?->description ?? 'Mantén tus productos, cantidades y niveles de inventario organizados.' }}
                                    </p>

                                </div>

                                <div class="rounded-2xl bg-slate-950 border border-slate-800 p-4">

                                    <div class="grid grid-cols-3 gap-3 mb-4">

                                        <div class="bg-slate-900 rounded-xl p-3">
                                            <div class="text-[10px] text-slate-500">Productos</div>
                                            <div class="font-bold mt-1">1,284</div>
                                        </div>

                                        <div class="bg-slate-900 rounded-xl p-3">
                                            <div class="text-[10px] text-slate-500">En existencia</div>
                                            <div class="font-bold mt-1">96%</div>
                                        </div>

                                        <div class="bg-slate-900 rounded-xl p-3">
                                            <div class="text-[10px] text-slate-500">Alertas</div>
                                            <div class="font-bold mt-1">24</div>
                                        </div>

                                    </div>

                                    @foreach([
                                        ['Laptop Pro', '24', 'Disponible'],
                                        ['Mouse inalámbrico', '142', 'Disponible'],
                                        ['Monitor 24"', '18', 'Stock bajo'],
                                        ['Teclado', '86', 'Disponible']
                                    ] as $product)

                                        <div class="flex items-center justify-between py-3 border-t border-slate-800">

                                            <span class="text-xs text-slate-300">
                                                {{ $product[0] }}
                                            </span>

                                            <span class="text-xs text-slate-500">
                                                {{ $product[1] }}
                                            </span>

                                            <span class="text-[10px] px-2 py-1 rounded-full {{ $product[2] === 'Stock bajo' ? 'bg-amber-500/10 text-amber-400' : 'bg-emerald-500/10 text-emerald-400' }}">
                                                {{ $product[2] }}
                                            </span>

                                        </div>

                                    @endforeach

                                </div>

                            </div>

                        </div>


                        {{-- PUNTO DE VENTA --}}
                        <div class="rounded-3xl border border-slate-800 bg-slate-900/70 p-7">

                            <span class="text-xs uppercase tracking-widest text-cyan-400 font-semibold">
                                Punto de venta
                            </span>

                            <h3 class="text-2xl font-semibold mt-3 mb-3">
                                {{ $pf1?->title ?? 'Vende de forma rápida y sencilla' }}
                            </h3>

                            <p class="text-sm text-slate-400 leading-7">
                                {{ $pf1?->description ?? 'Procesa tus ventas y mantén tu inventario actualizado automáticamente.' }}
                            </p>

                            <div class="mt-8 rounded-2xl bg-slate-950 border border-slate-800 p-5">

                                <div class="flex justify-between mb-5">

                                    <span class="text-sm text-slate-400">
                                        Venta actual
                                    </span>

                                    <span class="text-lg font-bold text-white">
                                        {{ $currencySymbol }}142.50
                                    </span>

                                </div>

                                <div class="space-y-2">

                                    @foreach(['Producto A', 'Producto B', 'Producto C'] as $item)

                                        <div class="flex justify-between bg-slate-900 rounded-lg px-3 py-2 text-xs">

                                            <span class="text-slate-400">
                                                {{ $item }}
                                            </span>

                                            <span>
                                                {{ $currencySymbol }}24.50
                                            </span>

                                        </div>

                                    @endforeach

                                </div>

                                <div class="mt-4 bg-indigo-600 rounded-xl text-center py-3 text-sm font-semibold">
                                    Registrar venta
                                </div>

                            </div>

                        </div>


                        {{-- ANALÍTICA --}}
                        <div class="rounded-3xl border border-slate-800 bg-slate-900/70 p-7">

                            <span class="text-xs uppercase tracking-widest text-emerald-400 font-semibold">
                                Reportes
                            </span>

                            <h3 class="text-2xl font-semibold mt-3 mb-3">
                                {{ $pf2?->title ?? 'Conoce el rendimiento de tu negocio' }}
                            </h3>

                            <p class="text-sm text-slate-400 leading-7">
                                {{ $pf2?->description ?? 'Obtén información clara mediante reportes y datos útiles para tomar mejores decisiones.' }}
                            </p>

                            <div class="mt-8 h-36 flex items-end gap-2">

                                @foreach([28,42,34,55,48,66,58,76,70,90] as $height)

                                    <div
                                        class="flex-1 rounded-t bg-gradient-to-t from-indigo-600 to-cyan-400"
                                        style="height:{{ $height }}%">
                                    </div>

                                @endforeach

                            </div>

                            <div class="flex justify-between text-[10px] text-slate-500 mt-3">
                                <span>Anterior</span>
                                <span>Actual</span>
                            </div>

                        </div>

                    </div>

                    <div class="mt-5 rounded-3xl border border-slate-800 bg-indigo-600 p-7 md:p-9 flex flex-col md:flex-row justify-between items-start md:items-center gap-7">

                        <div>

                            <h3 class="text-2xl font-semibold">
                                {{ $pf3?->title ?? 'Crea tu espacio de trabajo' }}
                            </h3>

                            <p class="text-indigo-100/80 text-sm mt-2 max-w-xl">
                                {{ $pf3?->description ?? 'Comienza a organizar y administrar tu negocio desde un solo lugar.' }}
                            </p>

                        </div>

                        <a
                            href="{{ route('central.register') }}"
                            class="inline-flex items-center gap-2 bg-white text-slate-950 px-6 py-3 rounded-full font-semibold hover:bg-slate-100 transition shrink-0">

                            Comenzar ahora

                            <iconify-icon icon="solar:arrow-right-linear"></iconify-icon>

                        </a>

                    </div>

                </div>

            </section>

        @endif


        {{-- =====================================================
             PRECIOS
        ====================================================== --}}
        @if(!empty($pricing['is_active']))

            <section
                id="pricing"
                class="py-20 md:py-24 px-5 sm:px-6 bg-white scroll-mt-24">

                <div class="max-w-7xl mx-auto">

                    <div class="max-w-2xl mx-auto text-center mb-10">

                        <span class="text-sm font-semibold text-indigo-600">
                            Planes y precios
                        </span>

                        <h2 class="mt-3 text-4xl md:text-5xl font-bold tracking-tight text-slate-950">

                            {{ $pricing['settings']->section_title ?? 'Elige el plan ideal para tu negocio' }}

                        </h2>

                        <p class="mt-4 text-slate-500">
                            {{ $pricing['settings']->section_subtitle ?? 'Planes simples y transparentes para ayudarte a crecer.' }}
                        </p>

                        @if($pricingHasYearly)

                            <div
                                class="inline-flex items-center p-1 bg-slate-100 rounded-full mt-6 border border-slate-200">

                                <button
                                    type="button"
                                    class="l3-price-tab px-6 py-2 rounded-full text-sm font-semibold bg-white shadow-sm text-slate-900"
                                    data-cycle="monthly">
                                    Mensual
                                </button>

                                <button
                                    type="button"
                                    class="l3-price-tab px-6 py-2 rounded-full text-sm font-semibold text-slate-500"
                                    data-cycle="yearly">
                                    Anual
                                </button>

                            </div>

                        @endif

                    </div>

                    @if($l3Plans->isNotEmpty())

                        <div class="l3-pricing-grid grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 max-w-7xl mx-auto">

                            @foreach($l3Plans as $i => $plan)

                                @php
                                    $featured = $l3Plans->count() >= 2 && $i === 1;
                                    $limits = $plan->limits ?? [];
                                    $planFeatures = $plan->features ?? [];

                                    $planColors = [
                                        [
                                            'bg' => 'bg-blue-50',
                                            'border' => 'border-blue-200',
                                            'accent' => 'text-blue-600',
                                            'button' => 'bg-blue-600 hover:bg-blue-700',
                                            'icon' => 'text-blue-500',
                                        ],
                                        [
                                            'bg' => 'bg-indigo-50',
                                            'border' => 'border-indigo-300',
                                            'accent' => 'text-indigo-600',
                                            'button' => 'bg-indigo-600 hover:bg-indigo-700',
                                            'icon' => 'text-indigo-500',
                                        ],
                                        [
                                            'bg' => 'bg-cyan-50',
                                            'border' => 'border-cyan-200',
                                            'accent' => 'text-cyan-600',
                                            'button' => 'bg-cyan-600 hover:bg-cyan-700',
                                            'icon' => 'text-cyan-500',
                                        ],
                                        [
                                            'bg' => 'bg-emerald-50',
                                            'border' => 'border-emerald-200',
                                            'accent' => 'text-emerald-600',
                                            'button' => 'bg-emerald-600 hover:bg-emerald-700',
                                            'icon' => 'text-emerald-500',
                                        ],
                                    ];

                                    $planColor = $planColors[$i % count($planColors)];
                                @endphp

                                <div
                                    class="l3-pricing-card {{ $featured ? 'featured' : '' }}">

                                    @if($featured)

                                        <div class="absolute top-0 left-1/2 -translate-x-1/2 -translate-y-1/2">

                                            <span class="bg-indigo-500 text-white text-[10px] font-bold px-3.5 py-1.5 rounded-full uppercase tracking-wide whitespace-nowrap">
                                                Más popular
                                            </span>

                                        </div>

                                    @endif

                                    <div class="flex items-start justify-between gap-3">

                                        <div>
                                            <h3 class="text-xl font-semibold text-slate-950">
                                                {{ $plan->name }}
                                            </h3>

                                            <p class="l3-plan-description text-xs mt-1.5 text-slate-500">
                                                @if($plan->price == 0)
                                                    Ideal para comenzar
                                                @else
                                                    Para negocios en crecimiento
                                                @endif
                                            </p>
                                        </div>

                                    </div>

                                    <div class="l3-plan-price-area">

                                        <div class="l3-plan-price" data-monthly>

                                            @if($plan->price == 0 && $plan->yearly_price == 0)

                                                <span class="text-4xl font-bold text-slate-950">
                                                    Gratis
                                                </span>

                                            @else

                                                <span class="text-4xl font-bold text-slate-950">
                                                    {{ $currencySymbol }}<span class="l3-amt-monthly">{{ $plan->price == floor($plan->price) ? number_format($plan->price, 0) : number_format($plan->price, 2) }}</span>
                                                </span>

                                                <span class="text-xs text-slate-500">
                                                    / mes
                                                </span>

                                            @endif

                                        </div>

                                        @if($plan->yearly_price > 0)

                                            <div class="l3-plan-price hidden" data-yearly>

                                                <span class="text-4xl font-bold text-slate-950">
                                                    {{ $currencySymbol }}<span class="l3-amt-yearly">{{ $plan->yearly_price == floor($plan->yearly_price) ? number_format($plan->yearly_price, 0) : number_format($plan->yearly_price, 2) }}</span>
                                                </span>

                                                <span class="text-xs text-slate-500">
                                                    / año
                                                </span>

                                                @if($plan->getYearlySavingsPercent() > 0)

                                                    <div class="text-[11px] text-indigo-500 font-medium mt-1">
                                                        Ahorras {{ $plan->getYearlySavingsPercent() }}%
                                                    </div>

                                                @endif

                                            </div>

                                        @endif

                                    </div>

                                    <a
                                        href="{{ route('central.register', ['plan' => $plan->id]) }}"
                                        class="l3-plan-button block text-center py-3 rounded-xl text-sm font-semibold transition {{ $featured
                                            ? 'bg-indigo-600 text-white hover:bg-indigo-700'
                                            : 'bg-slate-950 text-white hover:bg-indigo-600' }}">

                                        {{ $plan->isFree()
                                            ? 'Comenzar gratis'
                                            : ($plan->hasTrial()
                                                ? 'Comenzar prueba gratis'
                                                : 'Elegir plan') }}

                                    </a>

                                    <div class="l3-plan-included mt-auto">

                                        <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-3">
                                            Incluye
                                        </div>

                                        <ul class="l3-feature-list space-y-2.5 text-xs text-slate-600">

                                            @foreach($limits as $key => $value)

                                                @php
                                                    $meta = \App\Models\Central\Plan::AVAILABLE_LIMITS[$key] ?? null;
                                                @endphp

                                                @if($meta)

                                                    <li class="flex items-start gap-2">

                                                        <iconify-icon
                                                            icon="solar:check-circle-bold"
                                                            class="{{ $featured ? 'text-indigo-500' : 'text-emerald-500' }} text-base shrink-0">
                                                        </iconify-icon>

                                                        <span>
                                                            {{ $value == -1 ? 'Ilimitado' : $value }}
                                                            {{ $meta['label'] }}
                                                        </span>

                                                    </li>

                                                @endif

                                            @endforeach

                                            @foreach($planFeatures as $fKey)

                                                @php
                                                    $fMeta = \App\Models\Central\Plan::AVAILABLE_FEATURES[$fKey] ?? null;
                                                @endphp

                                                @if($fMeta)

                                                    <li class="flex items-start gap-2">

                                                        <iconify-icon
                                                            icon="solar:check-circle-bold"
                                                            class="{{ $featured ? 'text-indigo-500' : 'text-emerald-500' }} text-base shrink-0">
                                                        </iconify-icon>

                                                        <span>
                                                            {{ $fMeta['label'] }}
                                                        </span>

                                                    </li>

                                                @endif

                                            @endforeach

                                        </ul>

                                    </div>

                                </div>

                            @endforeach

                        </div>

                    @endif

                </div>

            </section>

        @endif


        {{-- =====================================================
             CÓMO FUNCIONA
        ====================================================== --}}
        @if(!empty($howItWorks['is_active']))

            <section
                id="journey"
                class="py-24 md:py-32 px-6 bg-slate-50 scroll-mt-24">

                <div class="max-w-6xl mx-auto">

                    <div class="max-w-2xl mx-auto text-center mb-16">

                        <span class="text-sm font-semibold text-indigo-600">
                            Cómo funciona
                        </span>

                        <h2 class="mt-3 text-4xl font-bold tracking-tight text-slate-950">
                            Comienza en pocos pasos
                        </h2>

                        <p class="mt-4 text-slate-500">
                            Conoce cómo comenzar a utilizar la plataforma de forma rápida y sencilla.
                        </p>

                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

                        @forelse($howItWorks['steps'] as $i => $step)

                            <div class="relative bg-white border border-slate-200 rounded-3xl p-7">

                                <div class="flex items-center justify-between mb-10">

                                    <span class="text-5xl font-bold text-slate-100">
                                        {{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}
                                    </span>

                                    @if($step->icon ?? null)

                                        <div class="w-11 h-11 rounded-xl bg-indigo-50 flex items-center justify-center">
                                            <i class="{{ $step->icon }} text-indigo-600"></i>
                                        </div>

                                    @endif

                                </div>

                                <h3 class="text-xl font-semibold text-slate-950 mb-3">
                                    {{ $step->title }}
                                </h3>

                                <p class="text-sm text-slate-500 leading-7">
                                    {{ $step->description }}
                                </p>

                            </div>

                        @empty

                            @foreach([
                                ['t' => 'Explora las características', 'd' => 'Conoce todas las herramientas disponibles para administrar tu negocio.'],
                                ['t' => 'Elige tu plan', 'd' => 'Selecciona el plan que mejor se adapte a las necesidades de tu negocio.'],
                                ['t' => 'Comienza a trabajar', 'd' => 'Configura tu espacio y empieza a gestionar tu negocio desde cualquier lugar.'],
                            ] as $j => $row)

                                <div class="bg-white border border-slate-200 rounded-3xl p-7">

                                    <div class="text-5xl font-bold text-slate-100 mb-10">
                                        {{ str_pad($j + 1, 2, '0', STR_PAD_LEFT) }}
                                    </div>

                                    <h3 class="text-xl font-semibold mb-3">
                                        {{ $row['t'] }}
                                    </h3>

                                    <p class="text-sm text-slate-500 leading-7">
                                        {{ $row['d'] }}
                                    </p>

                                </div>

                            @endforeach

                        @endforelse

                    </div>

                </div>

            </section>

        @endif


        {{-- =====================================================
             OPINIONES
        ====================================================== --}}
        @if($l3TestimonialsActive->isNotEmpty())

            <section
                id="testimonials"
                class="py-24 md:py-32 px-6 bg-white scroll-mt-24">

                <div class="max-w-7xl mx-auto">

                    <div class="max-w-2xl mb-14">

                        <span class="text-sm font-semibold text-indigo-600">
                            Opiniones
                        </span>

                        <h2 class="mt-3 text-4xl md:text-5xl font-bold tracking-tight">
                            Lo que dicen nuestros clientes
                        </h2>

                        <p class="mt-4 text-slate-500">
                            Conoce la experiencia de otros negocios que utilizan nuestra plataforma.
                        </p>

                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">

                        @foreach($l3TestimonialsActive as $testimonial)

                            <article class="rounded-3xl border border-slate-200 bg-slate-50 p-7">

                                @if($testimonial->rating)

                                    <div class="flex gap-1 text-amber-400 mb-6">

                                        @for($s = 1; $s <= 5; $s++)

                                            <i class="bi bi-star{{ $s <= $testimonial->rating ? '-fill' : '' }}"></i>

                                        @endfor

                                    </div>

                                @endif

                                <blockquote class="text-lg font-medium leading-8 text-slate-800 mb-8">
                                    “{{ $testimonial->review }}”
                                </blockquote>

                                <div class="flex items-center gap-3">

                                    <div class="w-11 h-11 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center font-semibold overflow-hidden">

                                        @if($testimonial->avatar)

                                            <img
                                                src="{{ asset($testimonial->avatar) }}"
                                                alt=""
                                                class="w-full h-full object-cover">

                                        @else

                                            {{ strtoupper(mb_substr($testimonial->client_name, 0, 1)) }}

                                        @endif

                                    </div>

                                    <div>

                                        <div class="text-sm font-semibold text-slate-950">
                                            {{ $testimonial->client_name }}
                                        </div>

                                        @if($testimonial->company_name)

                                            <div class="text-xs text-slate-500 mt-0.5">
                                                {{ $testimonial->company_name }}
                                            </div>

                                        @endif

                                    </div>

                                </div>

                            </article>

                        @endforeach

                    </div>

                </div>

            </section>

        @endif


        {{-- =====================================================
             PREGUNTAS FRECUENTES
        ====================================================== --}}
        @if($l3Faqs->isNotEmpty())

            <section
                id="faq"
                class="py-24 md:py-32 px-6 bg-slate-50 scroll-mt-24">

                <div class="max-w-3xl mx-auto">

                    <div class="text-center mb-14">

                        <span class="text-sm font-semibold text-indigo-600">
                            Preguntas frecuentes
                        </span>

                        <h2 class="mt-3 text-4xl font-bold tracking-tight text-slate-950">
                            Resolvemos tus dudas
                        </h2>

                        <p class="mt-4 text-slate-500">
                            Encuentra respuestas a las preguntas más comunes.
                        </p>

                    </div>

                    <div class="space-y-3">

                        @foreach($l3Faqs as $faq)

                            <details class="group bg-white border border-slate-200 rounded-2xl overflow-hidden">

                                <summary class="list-none cursor-pointer px-6 py-5 flex items-center justify-between gap-5 font-semibold text-slate-900">

                                    <span>
                                        {{ $faq->question }}
                                    </span>

                                    <span class="w-8 h-8 rounded-lg bg-slate-50 flex items-center justify-center shrink-0">

                                        <iconify-icon
                                            icon="solar:add-circle-linear"
                                            class="text-xl text-slate-400 group-open:hidden">
                                        </iconify-icon>

                                        <iconify-icon
                                            icon="solar:minus-circle-linear"
                                            class="text-xl text-indigo-500 hidden group-open:block">
                                        </iconify-icon>

                                    </span>

                                </summary>

                                <div class="px-6 pb-6 text-sm leading-7 text-slate-500">
                                    {!! nl2br(e($faq->answer)) !!}
                                </div>

                            </details>

                        @endforeach

                    </div>

                </div>

            </section>

        @endif


        {{-- =====================================================
             CTA
        ====================================================== --}}
        @if($l3CtaVisible)

            <section
                id="cta"
                class="relative overflow-hidden py-24 md:py-32 px-6 bg-slate-950 scroll-mt-24">

                @if($cta->background_image)

                    <div
                        class="absolute inset-0 bg-cover bg-center opacity-20"
                        style="background-image:url('{{ asset($cta->background_image) }}')">
                    </div>

                @endif

                <div class="absolute inset-0 bg-gradient-to-br from-indigo-900/60 via-slate-950 to-slate-950"></div>

                <div class="absolute -top-40 left-1/2 -translate-x-1/2 w-[700px] h-[500px] rounded-full bg-indigo-600/20 blur-[120px]"></div>

                <div class="relative max-w-4xl mx-auto text-center">

                    <span class="inline-flex items-center px-3 py-1.5 rounded-full bg-white/10 border border-white/10 text-xs font-semibold text-indigo-200 mb-7">
                        {{ $appName }}
                    </span>

                    <h2
                        id="l3-cta-title"
                        class="text-4xl md:text-6xl font-bold tracking-tight text-white">

                        {{ $cta->title }}

                    </h2>

                    @if($cta->subtitle)

                        <p class="max-w-2xl mx-auto mt-6 text-lg text-slate-300 leading-relaxed">
                            {{ $cta->subtitle }}
                        </p>

                    @endif

                    <div class="flex flex-col sm:flex-row justify-center gap-3 mt-9">

                        <a
                            href="{{ $cta->button_url ?? route('central.register') }}"
                            class="inline-flex items-center justify-center gap-2 px-8 py-4 rounded-full bg-white text-slate-950 font-semibold hover:bg-slate-100 transition">

                            {{ $cta->button_text ?: 'Prueba gratis' }}

                            <iconify-icon icon="solar:arrow-right-linear"></iconify-icon>

                        </a>

                        <a
                            href="{{ $l3SalesContactHref }}"
                            @if($l3SalesContactExternal) target="_blank" rel="noopener noreferrer" @endif
                            class="inline-flex items-center justify-center gap-2 px-8 py-4 rounded-full border border-white/20 bg-white/10 text-white font-semibold hover:bg-white/15 transition">

                            {{ $cta->sales_button_text ?: 'Hablar con Ventas' }}

                            <iconify-icon icon="solar:chat-round-call-linear"></iconify-icon>

                        </a>

                    </div>

                </div>

            </section>

        @endif

    </main>


    {{-- =========================================================
         FOOTER
    ========================================================== --}}
    <footer class="bg-white border-t border-slate-200">

        <div class="max-w-7xl mx-auto px-6 pt-16 pb-10">

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-10">

                <div class="sm:col-span-2">

                    <a
                        href="{{ route('central.welcome') }}"
                        class="inline-flex items-center gap-3 mb-5">

                        <span class="w-9 h-9 rounded-xl bg-slate-950 text-white flex items-center justify-center overflow-hidden">

                            @if($logoUrl)

                                <img
                                    src="{{ $logoUrl }}"
                                    alt=""
                                    class="w-full h-full object-cover">

                            @else

                                <iconify-icon icon="solar:box-minimalistic-linear"></iconify-icon>

                            @endif

                        </span>

                        @if($generalSettings->show_site_name ?? true)

                            <span class="font-bold text-lg tracking-tight">
                                {{ $appName }}
                            </span>

                        @endif

                    </a>

                    @if($footer && $footer->footer_about)

                        <p class="text-sm text-slate-500 leading-7 max-w-sm">
                            {{ $footer->footer_about }}
                        </p>

                    @endif

                    @if($footer)

                        <div class="flex items-center gap-4 mt-6">

                            @foreach([
                                'facebook' => ['icon' => 'bi-facebook', 'label' => 'Facebook'],
                                'twitter' => ['icon' => 'bi-twitter-x', 'label' => 'X'],
                                'linkedin' => ['icon' => 'bi-linkedin', 'label' => 'LinkedIn'],
                                'instagram' => ['icon' => 'bi-instagram', 'label' => 'Instagram'],
                                'youtube' => ['icon' => 'bi-youtube', 'label' => 'YouTube'],
                            ] as $social => $meta)

                                @if($footer->$social)

                                    <a
                                        href="{{ $footer->$social }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        aria-label="{{ $meta['label'] }}"
                                        class="w-9 h-9 rounded-lg bg-slate-50 flex items-center justify-center text-slate-400 hover:text-slate-950 hover:bg-slate-100 transition">

                                        <i class="bi {{ $meta['icon'] }}"></i>

                                    </a>

                                @endif

                            @endforeach

                        </div>

                    @endif

                </div>


                {{-- CONTACTO --}}
                <div>

                    <h4 class="font-semibold text-sm text-slate-950 mb-5">
                        Contáctanos
                    </h4>

                    <ul id="contact-sales" class="space-y-3 text-sm text-slate-500">

                        @if($l3SalesEmail)

                            <li>
                                <a
                                    href="{{ $l3SalesEmailHref }}"
                                    class="hover:text-slate-950 transition break-all">
                                    {{ $l3SalesEmail }}
                                </a>
                            </li>

                        @endif

                        @if($l3SalesWhatsappHref)

                            <li>
                                <a
                                    href="{{ $l3SalesWhatsappHref }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-2 hover:text-slate-950 transition">
                                    <i class="bi bi-whatsapp"></i>
                                    WhatsApp ventas
                                </a>
                            </li>

                        @endif

                        @if($footer && $footer->contact_phone)

                            <li>
                                <a
                                    href="tel:{{ preg_replace('/[^0-9+]/', '', $footer->contact_phone) }}"
                                    class="hover:text-slate-950 transition">
                                    {{ $footer->contact_phone }}
                                </a>
                            </li>

                        @endif

                        @if($footer && $footer->address)

                            <li class="leading-6">
                                {{ $footer->address }}
                            </li>

                        @endif

                        @if(! $l3SalesEmail && ! $l3SalesWhatsappHref && ! optional($footer)->contact_phone && ! optional($footer)->address)

                            <li>{{ $appName }}</li>

                        @endif

                    </ul>

                </div>


                {{-- ENLACES UTILES --}}
                <div>

                    <h4 class="font-semibold text-sm text-slate-950 mb-5">
                        Enlaces útiles
                    </h4>

                    <ul class="space-y-3 text-sm text-slate-500">

                        @if(!empty($features['is_active']))

                            <li>
                                <a href="#features" class="hover:text-slate-950 transition">
                                    Funciones
                                </a>
                            </li>

                        @endif

                        @if(!empty($howItWorks['is_active']))

                            <li>
                                <a href="#journey" class="hover:text-slate-950 transition">
                                    Cómo funciona
                                </a>
                            </li>

                        @endif

                        @if($l3TestimonialsActive->isNotEmpty())

                            <li>
                                <a href="#testimonials" class="hover:text-slate-950 transition">
                                    Opiniones
                                </a>
                            </li>

                        @endif

                        @if($l3Faqs->isNotEmpty())

                            <li>
                                <a href="#faq" class="hover:text-slate-950 transition">
                                    Preguntas frecuentes
                                </a>
                            </li>

                        @endif

                        @if(!empty($pricing['is_active']))

                            <li>
                                <a href="#pricing" class="hover:text-slate-950 transition">
                                    Planes
                                </a>
                            </li>

                        @endif

                    </ul>

                </div>


                {{-- SOPORTE --}}
                <div>

                    <h4 class="font-semibold text-sm text-slate-950 mb-5">
                        Soporte
                    </h4>

                    <ul class="space-y-3 text-sm text-slate-500">

                        <li>
                            <a
                                href="{{ $l3SalesContactHref }}"
                                @if($l3SalesContactExternal) target="_blank" rel="noopener noreferrer" @endif
                                class="hover:text-slate-950 transition">
                                Ventas
                            </a>
                        </li>

                        @if($footer && $footer->contact_email)

                            <li>
                                <a
                                    href="mailto:{{ $footer->contact_email }}"
                                    class="hover:text-slate-950 transition break-all">
                                    Ayuda
                                </a>
                            </li>

                        @endif

                        @if($footer && ($footer->show_admin_login ?? false))

                            <li>
                                <a
                                    href="{{ route('central.login') }}"
                                    class="hover:text-slate-950 transition">
                                    Iniciar sesión
                                </a>
                            </li>

                        @endif

                        <li>
                            <a
                                href="#"
                                id="cookiePreferencesLink"
                                class="hover:text-slate-950 transition">
                                Preferencias de cookies
                            </a>
                        </li>

                    </ul>

                </div>


                {{-- COMPANIA --}}
                <div>

                    <h4 class="font-semibold text-sm text-slate-950 mb-5">
                        Compañía
                    </h4>

                    <ul class="space-y-3 text-sm text-slate-500">

                        <li>
                            <a href="{{ route('central.welcome') }}" class="hover:text-slate-950 transition">
                                Inicio
                            </a>
                        </li>

                        <li>
                            <a
                                href="{{ route('central.register') }}"
                                class="hover:text-slate-950 transition">
                                Registrarse
                            </a>
                        </li>

                        <li>
                            <a
                                href="{{ route('central.privacy-policy') }}"
                                class="hover:text-slate-950 transition">
                                Privacidad
                            </a>
                        </li>

                        <li>
                            <a
                                href="{{ route('central.terms-conditions') }}"
                                class="hover:text-slate-950 transition">
                                Términos
                            </a>
                        </li>

                    </ul>


                </div>

            </div>


            <div class="mt-14 pt-7 border-t border-slate-100 flex flex-col md:flex-row items-center justify-between gap-4">

                <p class="text-xs text-slate-400 text-center md:text-start">
                    {{ optional($footer)->copyright_text ?: ('© ' . date('Y') . ' ' . $appName . '. Todos los derechos reservados.') }}
                </p>

                <a
                    href="{{ route('central.privacy-policy') }}"
                    class="text-xs text-slate-400 hover:text-slate-900 transition">
                    Política de privacidad
                </a>

            </div>

        </div>

    </footer>


    @if($footer && ($footer->show_sales_floating_button ?? false) && $l3SalesWhatsappHref)

        <a
            href="{{ $l3SalesWhatsappHref }}"
            target="_blank"
            rel="noopener noreferrer"
            class="l3-sales-float fixed right-4 bottom-4 md:right-6 md:bottom-6 z-[80] inline-flex items-center gap-2 rounded-full bg-slate-950 px-4 py-3 text-sm font-semibold text-white shadow-xl shadow-slate-900/20 transition hover:bg-indigo-600"
            aria-label="Habla con Ventas">

            <i class="bi bi-whatsapp text-base"></i>
            <span>Habla con Ventas</span>

        </a>

    @endif


    {{-- =========================================================
         COOKIES
    ========================================================== --}}
    <div
        id="cookieConsent"
        class="fixed bottom-4 left-4 right-4 max-w-xl mx-auto z-[100] p-5 rounded-2xl bg-white border border-slate-200 shadow-2xl shadow-slate-900/15 translate-y-[120%] opacity-0 transition-all duration-300 pointer-events-none">

        <h4 class="text-sm font-bold text-slate-950 mb-1">
            Uso de cookies
        </h4>

        <p class="text-xs text-slate-500 leading-6 mb-4">

            Utilizamos cookies para mejorar tu experiencia en la plataforma.

            <a
                href="{{ route('central.privacy-policy') }}#cookies"
                class="text-indigo-600 hover:underline">
                Política de privacidad
            </a>

        </p>

        <div class="flex flex-wrap gap-2">

            <button
                type="button"
                id="cookieAcceptBtn"
                class="px-4 py-2 rounded-full bg-slate-950 text-white text-xs font-semibold hover:bg-indigo-600 transition">
                Aceptar todas
            </button>

            <button
                type="button"
                id="cookieRejectBtn"
                class="px-4 py-2 rounded-full border border-slate-200 text-slate-700 text-xs font-semibold hover:bg-slate-50">
                Rechazar
            </button>

            <button
                type="button"
                id="cookieCustomizeBtn"
                class="px-4 py-2 rounded-full text-indigo-600 text-xs font-semibold hover:bg-indigo-50">
                Personalizar
            </button>

        </div>

        <div
            id="cookieCustomize"
            class="hidden mt-4 pt-4 border-t border-slate-100">

            <p class="text-xs text-slate-500 mb-3">
                Selecciona qué tipos de cookies deseas permitir.
            </p>

            <label class="flex items-center gap-2 text-xs text-slate-700 mb-3">
                <input
                    type="checkbox"
                    id="cookieAnalytics"
                    class="rounded border-slate-300">
                Cookies de análisis
            </label>

            <label class="flex items-center gap-2 text-xs text-slate-700 mb-3">
                <input
                    type="checkbox"
                    id="cookieMarketing"
                    class="rounded border-slate-300">
                Cookies de marketing
            </label>

            <button
                type="button"
                id="cookieSaveBtn"
                class="px-4 py-2 rounded-full bg-indigo-600 text-white text-xs font-semibold hover:bg-indigo-500">
                Guardar preferencias
            </button>

        </div>

    </div>


    <script src="{{ asset('assets_super/js/landing-three.js') }}"></script>

</body>
</html>
