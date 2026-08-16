<!DOCTYPE html>
@php
    $isRtl = in_array(app()->getLocale(), ['ar', 'he', 'fa', 'ur']);
    $generalSettings = \App\Models\Central\GeneralSetting::instance();
    $appName = $generalSettings->app_name ?: 'Stocky';
    $logoUrl = $generalSettings->getLogoUrl();
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
    <link href="{{ asset('assets_super/css/landing-three-page.css') }}" rel="stylesheet">
    @include('central.partials.landing-font')
</head>
@php
    $l3HeroVisible = $hero && ($hero->is_active ?? true);
    $l3CtaVisible = $cta && ($cta->is_active ?? true);
    $l3Faqs = isset($faqs) ? $faqs->filter(fn ($f) => ($f->is_active ?? true)) : collect();
    $l3FaqsVisible = $l3Faqs->isNotEmpty();
    $l3TestimonialsActive = isset($testimonials) ? $testimonials->filter(fn ($t) => ($t->is_active ?? true)) : collect();
    $l3TestimonialsVisible = $l3TestimonialsActive->isNotEmpty();
    $l3FeatureItems = ($features['items'] ?? collect());
    $l3PlatformVisible = !empty($features['is_active']) && ($l3FeatureItems->isNotEmpty() || ($features['section'] ?? null));
@endphp
<body class="bg-slate-50 text-slate-900 antialiased overflow-x-hidden selection:bg-indigo-100 selection:text-indigo-900 relative">

    <div class="absolute top-0 inset-x-0 h-screen overflow-hidden -z-10 pointer-events-none flex justify-center">
        <div class="w-full max-w-7xl relative">
            <div class="absolute top-[-10%] left-[-10%] w-[50%] h-[50%] rounded-full bg-gradient-to-br from-indigo-400/20 to-purple-400/10 blur-[100px]"></div>
            <div class="absolute top-[10%] right-[-5%] w-[40%] h-[40%] rounded-full bg-gradient-to-bl from-blue-400/20 to-cyan-300/10 blur-[100px]"></div>
        </div>
    </div>

    <header class="fixed top-0 inset-x-0 z-50 bg-white/70 backdrop-blur-md border-b border-slate-200/50 transition-shadow" id="l3Header">
        <div class="max-w-7xl mx-auto px-6 h-16 flex items-center justify-between gap-4">
            <a class="flex items-center gap-2 shrink-0" href="{{ route('central.welcome') }}">
                <span class="w-8 h-8 rounded-lg bg-slate-900 flex items-center justify-center text-white overflow-hidden">
                    @if($logoUrl)
                        <img src="{{ $logoUrl }}" alt="" class="w-full h-full object-cover">
                    @else
                        <iconify-icon icon="solar:box-minimalistic-linear" class="text-xl"></iconify-icon>
                    @endif
                </span>
                @if($generalSettings->show_site_name ?? true)
                    <span class="text-xl tracking-tighter font-semibold text-slate-900">{{ $appName }}</span>
                @endif
            </a>

            <nav class="hidden lg:flex items-center gap-8 text-sm font-medium text-slate-500" aria-label="{{ __('landing.quick_links') }}">
                @if(!empty($features['is_active']))
                    <a href="#features" class="hover:text-slate-900 transition-colors">{{ __('landing.features') }}</a>
                @endif
                @if($l3PlatformVisible)
                    <a href="#platform" class="hover:text-slate-900 transition-colors">Platform</a>
                @endif
                @if(!empty($pricing['is_active']))
                    <a href="#pricing" class="hover:text-slate-900 transition-colors">{{ __('landing.pricing') }}</a>
                @endif
                @if(!empty($howItWorks['is_active']))
                    <a href="#journey" class="hover:text-slate-900 transition-colors">{{ __('landing.how_it_works') }}</a>
                @endif
                @if($l3TestimonialsVisible)
                    <a href="#testimonials" class="hover:text-slate-900 transition-colors">{{ __('landing.testimonials') }}</a>
                @endif
                @if($l3FaqsVisible)
                    <a href="#faq" class="hover:text-slate-900 transition-colors">{{ __('landing.faq') }}</a>
                @endif
            </nav>

            <div class="flex items-center gap-2 sm:gap-4 text-sm font-medium">
                @if(isset($languages) && $languages->count() > 1)
                <div class="relative hidden sm:block" id="l3Lang">
                    <button type="button" class="flex items-center gap-1.5 text-slate-600 hover:text-slate-900 px-2 py-1 rounded-lg hover:bg-slate-100/80 transition-colors text-xs font-medium" id="l3LangBtn" aria-expanded="false" aria-haspopup="true">
                        <i class="bi bi-globe2" aria-hidden="true"></i>
                        {{ strtoupper($currentLocale ?? app()->getLocale()) }}
                        <i class="bi bi-chevron-down text-[0.65rem]" aria-hidden="true"></i>
                    </button>
                    <div class="hidden absolute end-0 mt-1 min-w-[10rem] py-1 bg-white border border-slate-200 rounded-xl shadow-lg z-50" id="l3LangMenu" role="menu">
                        @foreach($languages as $lang)
                        <form method="POST" action="{{ route('central.locale', $lang->locale) }}">
                            @csrf
                            <button type="submit" class="w-full text-start px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 flex items-center gap-2 {{ ($currentLocale ?? app()->getLocale()) === $lang->locale ? 'bg-indigo-50 text-indigo-900' : '' }}" role="menuitem">
                                @if($lang->flag)
                                    <img src="{{ asset('flags/' . $lang->flag) }}" alt="" class="w-5 h-auto rounded-sm">
                                @endif
                                {{ $lang->name }}
                            </button>
                        </form>
                        @endforeach
                    </div>
                </div>
                @endif
                @if($footer && ($footer->show_admin_login ?? false))
                    <a href="{{ route('central.login') }}" class="hidden md:inline text-slate-600 hover:text-slate-900 transition-colors">{{ __('landing.admin_login') }}</a>
                @endif
                <a href="{{ route('central.register') }}" class="hidden sm:inline bg-slate-900 text-white px-4 py-2 rounded-full hover:bg-slate-800 transition-all shadow-sm shadow-slate-900/10">{{ __('landing.sign_up_free') }}</a>
                <button type="button" class="lg:hidden p-2 rounded-lg text-slate-700 hover:bg-slate-100" id="l3OpenMenu" aria-expanded="false" aria-controls="l3Drawer" aria-label="Menu">
                    <i class="bi bi-list text-xl" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </header>

    <div class="fixed inset-0 z-40 lg:hidden hidden" id="l3Drawer" aria-hidden="true">
        <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" data-l3-drawer-backdrop></div>
        <div class="absolute top-0 end-0 h-full w-[min(20rem,100%)] bg-white shadow-xl flex flex-col p-6" id="l3DrawerPanel" role="dialog" aria-modal="true" aria-label="Menu">
            <div class="flex justify-end mb-6">
                <button type="button" class="p-2 rounded-lg hover:bg-slate-100" id="l3CloseMenu" aria-label="Close">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
            </div>
            <nav class="flex flex-col gap-1 text-base font-medium text-slate-700" aria-label="{{ __('landing.quick_links') }}">
                @if(!empty($features['is_active']))
                    <a href="#features" class="py-2 px-3 rounded-lg hover:bg-slate-50">{{ __('landing.features') }}</a>
                @endif
                @if($l3PlatformVisible)
                    <a href="#platform" class="py-2 px-3 rounded-lg hover:bg-slate-50">Platform</a>
                @endif
                @if(!empty($pricing['is_active']))
                    <a href="#pricing" class="py-2 px-3 rounded-lg hover:bg-slate-50">{{ __('landing.pricing') }}</a>
                @endif
                @if(!empty($howItWorks['is_active']))
                    <a href="#journey" class="py-2 px-3 rounded-lg hover:bg-slate-50">{{ __('landing.how_it_works') }}</a>
                @endif
                @if($l3TestimonialsVisible)
                    <a href="#testimonials" class="py-2 px-3 rounded-lg hover:bg-slate-50">{{ __('landing.testimonials') }}</a>
                @endif
                @if($l3FaqsVisible)
                    <a href="#faq" class="py-2 px-3 rounded-lg hover:bg-slate-50">{{ __('landing.faq') }}</a>
                @endif
            </nav>
            <div class="mt-auto pt-8 flex flex-col gap-3 border-t border-slate-100">
                @if($footer && ($footer->show_admin_login ?? false))
                    <a href="{{ route('central.login') }}" class="py-3 px-4 rounded-xl border border-slate-200 text-center font-medium">{{ __('landing.admin_login') }}</a>
                @endif
                <a href="{{ route('central.register') }}" class="py-3 px-4 rounded-xl bg-slate-900 text-white text-center font-medium">{{ __('landing.sign_up_free') }}</a>
            </div>
        </div>
    </div>

    <main id="main">
        @if($l3HeroVisible)
        {{-- Hero (CMS: Hero section — respects is_active, background_image, hero_image) --}}
        <section class="relative pt-32 pb-20 md:pt-48 md:pb-32 px-6 scroll-mt-24 overflow-hidden" id="top" aria-labelledby="l3-hero-title" @if($hero->background_image) style="background-image:url('{{ asset($hero->background_image) }}');background-size:cover;background-position:center" @endif>
            @if($hero->background_image)
            <div class="absolute inset-0 bg-slate-50/90 -z-0" aria-hidden="true"></div>
            @endif
            <div class="relative z-[1]">
            <div class="max-w-4xl mx-auto text-center opacity-0 animate-fade-in-up">
                @if($hero && $hero->subtitle)
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-indigo-50 border border-indigo-100 text-indigo-700 text-xs font-medium mb-8">
                        <span class="flex h-2 w-2 rounded-full bg-indigo-500"></span>
                        {{ $hero->subtitle }}
                    </div>
                @else
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-indigo-50 border border-indigo-100 text-indigo-700 text-xs font-medium mb-8">
                        <span class="flex h-2 w-2 rounded-full bg-indigo-500"></span>
                        {{ $appName }} — {{ __('landing.start_free_trial') }}
                    </div>
                @endif
                <h1 class="text-5xl md:text-7xl font-semibold tracking-tighter text-slate-900 mb-6 leading-[1.1]" id="l3-hero-title">
                    @if($hero && $hero->title)
                        {!! $hero->title !!}
                    @else
                        {{ __('landing.launch_title') }} <span class="text-transparent bg-clip-text bg-gradient-to-r from-indigo-600 to-blue-500">{{ $appName }}</span>
                    @endif
                </h1>
                @if($hero && $hero->description)
                    <p class="text-lg md:text-xl text-slate-600 mb-10 max-w-2xl mx-auto leading-relaxed">{{ $hero->description }}</p>
                @else
                    <p class="text-lg md:text-xl text-slate-600 mb-10 max-w-2xl mx-auto leading-relaxed">{{ __('landing.launch_desc') }}</p>
                @endif
                <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                    @if($hero && $hero->primary_button_text)
                        <a href="{{ $hero->primary_button_url ?? route('central.register') }}" class="w-full sm:w-auto bg-slate-900 text-white px-6 py-3 rounded-full font-medium hover:bg-slate-800 transition-all shadow-lg shadow-slate-900/20 flex items-center justify-center gap-2">
                            {{ $hero->primary_button_text }}
                            <iconify-icon icon="solar:arrow-right-linear" class="text-lg"></iconify-icon>
                        </a>
                    @else
                        <a href="{{ route('central.register') }}" class="w-full sm:w-auto bg-slate-900 text-white px-6 py-3 rounded-full font-medium hover:bg-slate-800 transition-all shadow-lg shadow-slate-900/20 flex items-center justify-center gap-2">
                            {{ __('landing.start_free_trial') }}
                            <iconify-icon icon="solar:arrow-right-linear" class="text-lg"></iconify-icon>
                        </a>
                    @endif
                    @if($hero && $hero->secondary_button_text)
                        <a href="{{ $hero->secondary_button_url ?? ($l3PlatformVisible ? '#platform' : '#features') }}" class="w-full sm:w-auto bg-white text-slate-700 border border-slate-200 px-6 py-3 rounded-full font-medium hover:bg-slate-50 transition-all flex items-center justify-center gap-2">
                            <iconify-icon icon="solar:play-circle-linear" class="text-lg"></iconify-icon>
                            {{ $hero->secondary_button_text }}
                        </a>
                    @else
                        <a href="{{ $l3PlatformVisible ? '#platform' : '#features' }}" class="w-full sm:w-auto bg-white text-slate-700 border border-slate-200 px-6 py-3 rounded-full font-medium hover:bg-slate-50 transition-all flex items-center justify-center gap-2">
                            <iconify-icon icon="solar:play-circle-linear" class="text-lg"></iconify-icon>
                            View demo
                        </a>
                    @endif
                </div>

                @if(session('success') && session('tenant_url'))
                    <div class="mt-6 p-4 rounded-2xl bg-emerald-50 border border-emerald-100 text-emerald-900 text-sm text-start max-w-xl mx-auto" role="status">
                        {{ session('message') }}<br>
                        <a href="{{ session('tenant_url') }}" class="font-medium underline">{{ session('tenant_url') }}</a>
                    </div>
                @endif
            </div>

            @if($hero && $hero->hero_image)
            <div class="max-w-5xl mx-auto mt-20 relative opacity-0 animate-fade-in-up delay-200">
                <div class="rounded-2xl border border-slate-200/60 shadow-2xl shadow-slate-300/40 overflow-hidden bg-white">
                    <img src="{{ asset($hero->hero_image) }}" alt="{{ strip_tags($hero->title ?? $appName) }}" class="w-full h-auto block" decoding="async">
                </div>
            </div>
            @endif
            </div>
        </section>
        @else
        <div class="pt-20 md:pt-24 shrink-0" aria-hidden="true"></div>
        @endif

        @if(isset($stats) && $stats->isNotEmpty())
        <section class="py-12 border-y border-slate-200/50 bg-white/50 scroll-mt-24" aria-labelledby="l3-trust-title">
            <div class="max-w-7xl mx-auto px-6">
                <p class="text-center text-sm font-medium text-slate-500 mb-8 tracking-tight uppercase" id="l3-trust-title">{{ __('landing.trust_bar_eyebrow') }}</p>
                <div class="flex flex-wrap justify-center items-center gap-x-12 gap-y-6 md:gap-x-24">
                    @foreach($stats as $stat)
                        <div class="text-center">
                            <span class="block text-2xl md:text-3xl font-semibold tracking-tight text-slate-800">{{ $stat->value }}</span>
                            <span class="text-xs text-slate-500 font-medium">{{ $stat->label }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
        @endif

        @if(!empty($features['is_active']) && $features['items']->isNotEmpty())
        <section id="features" class="py-24 px-6 scroll-mt-24" aria-labelledby="l3-features-title">
            <div class="max-w-7xl mx-auto">
                <div class="text-center max-w-2xl mx-auto mb-16">
                    @if($features['section'])
                        <h2 class="text-3xl md:text-4xl font-semibold tracking-tight text-slate-900 mb-4" id="l3-features-title">{{ $features['section']->section_title }}</h2>
                        <p class="text-slate-600">{{ $features['section']->section_subtitle }}</p>
                    @else
                        <h2 class="text-3xl md:text-4xl font-semibold tracking-tight text-slate-900 mb-4" id="l3-features-title">{{ __('landing.features') }}</h2>
                    @endif
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    @foreach($features['items'] as $i => $feature)
                    <div class="bg-white border border-slate-200/60 rounded-2xl p-8 shadow-sm hover:shadow-md transition-all group">
                        <div class="w-12 h-12 rounded-xl bg-indigo-50 flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
                            @if($feature->image)
                                <img src="{{ asset($feature->image) }}" alt="" class="w-7 h-7 object-contain">
                            @elseif($feature->icon)
                                <i class="{{ $feature->icon }} text-2xl text-indigo-600"></i>
                            @else
                                <iconify-icon icon="solar:box-linear" class="text-2xl text-indigo-600"></iconify-icon>
                            @endif
                        </div>
                        <h3 class="text-lg font-semibold tracking-tight text-slate-900 mb-2">{{ $feature->title }}</h3>
                        @if($feature->description)
                            <p class="text-sm text-slate-500 leading-relaxed">{{ $feature->description }}</p>
                        @endif
                    </div>
                    @endforeach
                </div>
            </div>
        </section>
        @endif

        @if($l3PlatformVisible)
        @php
            $pf0 = $l3FeatureItems->get(0);
            $pf1 = $l3FeatureItems->get(1);
            $pf2 = $l3FeatureItems->get(2);
            $pf3 = $l3FeatureItems->get(3);
        @endphp
        <section id="platform" class="py-24 px-6 bg-slate-900 text-white relative overflow-hidden scroll-mt-24" aria-labelledby="l3-platform-title">
            <div class="absolute top-0 right-0 w-[600px] h-[600px] bg-indigo-600/20 rounded-full blur-[120px] pointer-events-none"></div>
            <div class="absolute bottom-0 left-0 w-[500px] h-[500px] bg-blue-600/20 rounded-full blur-[120px] pointer-events-none"></div>
            <div class="max-w-7xl mx-auto relative z-10">
                <div class="mb-16 md:w-2/3">
                    <h2 class="text-3xl md:text-5xl font-semibold tracking-tight mb-4" id="l3-platform-title">{{ optional($features['section'])->section_title ?? __('landing.launch_title') }}</h2>
                    <p class="text-slate-400 text-lg">{{ optional($features['section'])->section_subtitle ?? __('landing.launch_desc') }}</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="md:col-span-2 bg-slate-800/50 backdrop-blur-sm border border-slate-700/50 rounded-3xl p-8 flex flex-col justify-between group overflow-hidden relative">
                        <div class="relative z-10 mb-12">
                            <h3 class="text-xl font-medium tracking-tight mb-2">{{ $pf0?->title ?? __('landing.feat_warehouse') }}</h3>
                            <p class="text-slate-400 text-sm max-w-sm">{{ $pf0?->description ?? __('landing.hiw_data_desc') }}</p>
                        </div>
                        <div class="relative w-full h-48 bg-slate-900/50 rounded-xl border border-slate-700/50 flex items-center justify-center overflow-hidden">
                            <div class="absolute top-8 left-12 w-3 h-3 bg-indigo-500 rounded-full shadow-[0_0_15px_rgba(99,102,241,0.8)]"></div>
                            <div class="absolute top-20 left-1/3 w-3 h-3 bg-emerald-500 rounded-full shadow-[0_0_15px_rgba(16,185,129,0.8)]"></div>
                            <div class="absolute bottom-12 right-1/4 w-3 h-3 bg-blue-500 rounded-full shadow-[0_0_15px_rgba(59,130,246,0.8)]"></div>
                            <div class="absolute top-9 left-14 w-32 h-12 border-t-2 border-l-2 border-dashed border-slate-600 rounded-tl-full opacity-50"></div>
                            <div class="absolute bottom-14 left-1/3 w-40 h-20 border-b-2 border-r-2 border-dashed border-slate-600 rounded-br-full opacity-50"></div>
                            <div class="px-4 py-2 bg-slate-800/80 backdrop-blur text-xs rounded-lg border border-slate-700 absolute top-16 left-32">
                                <span class="text-emerald-400 font-medium">{{ __('landing.unlimited') }}</span> (NYC)
                            </div>
                        </div>
                    </div>
                    <div class="bg-slate-800/50 backdrop-blur-sm border border-slate-700/50 rounded-3xl p-8 flex flex-col justify-between">
                        <div>
                            <h3 class="text-xl font-medium tracking-tight mb-2">{{ $pf1?->title ?? __('landing.feat_pos') }}</h3>
                            <p class="text-slate-400 text-sm">{{ $pf1?->description ?? __('landing.feat_ready') }}</p>
                        </div>
                        <div class="mt-8 bg-slate-900/80 rounded-xl border border-slate-700/50 p-4">
                            <div class="flex justify-between items-center mb-4 border-b border-slate-700/50 pb-2">
                                <span class="text-sm font-medium">Total</span>
                                <span class="text-lg font-semibold text-emerald-400">{{ $currencySymbol }}142.50</span>
                            </div>
                            <div class="grid grid-cols-3 gap-2 text-center text-sm font-medium">
                                @for($n = 1; $n <= 6; $n++)
                                <div class="bg-slate-800 py-2 rounded hover:bg-slate-700 cursor-pointer transition">{{ $n }}</div>
                                @endfor
                                <div class="col-span-3 bg-indigo-600 text-white py-2 rounded mt-2 hover:bg-indigo-500 cursor-pointer transition">{{ __('landing.get_started') }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="bg-slate-800/50 backdrop-blur-sm border border-slate-700/50 rounded-3xl p-8 flex flex-col justify-between">
                        <div>
                            <h3 class="text-xl font-medium tracking-tight mb-2">{{ $pf2?->title ?? __('landing.stat_analytics') }}</h3>
                            <p class="text-slate-400 text-sm">{{ $pf2?->description ?? __('landing.testimonials_subtitle') }}</p>
                        </div>
                        <div class="mt-8 flex flex-col gap-3">
                            @forelse(isset($stats) && $stats->isNotEmpty() ? $stats->take(3) : collect() as $st)
                            <div class="bg-slate-900/80 rounded-xl border border-slate-700/50 p-3 flex justify-between items-center gap-3">
                                <div class="flex items-center gap-2 min-w-0">
                                    <div class="w-2 h-2 rounded-full bg-indigo-400 shrink-0"></div>
                                    <span class="text-sm text-slate-300 truncate">{{ $st->label }}</span>
                                </div>
                                <span class="font-medium shrink-0">{{ $st->value }}</span>
                            </div>
                            @empty
                            <div class="bg-slate-900/80 rounded-xl border border-slate-700/50 p-3 flex justify-between items-center">
                                <div class="flex items-center gap-2">
                                    <div class="w-2 h-2 rounded-full bg-emerald-500"></div>
                                    <span class="text-sm text-slate-300">{{ __('landing.stat_secure') }}</span>
                                </div>
                                <span class="font-medium">42.8%</span>
                            </div>
                            <div class="bg-slate-900/80 rounded-xl border border-slate-700/50 p-3 flex justify-between items-center">
                                <div class="flex items-center gap-2">
                                    <div class="w-2 h-2 rounded-full bg-indigo-500"></div>
                                    <span class="text-sm text-slate-300">{{ __('landing.yearly') }}</span>
                                </div>
                                <span class="font-medium">12 {{ __('landing.mo') }}</span>
                            </div>
                            <div class="bg-slate-900/80 rounded-xl border border-slate-700/50 p-3 flex justify-between items-center">
                                <div class="flex items-center gap-2">
                                    <div class="w-2 h-2 rounded-full bg-amber-500"></div>
                                    <span class="text-sm text-slate-300">{{ __('landing.save') }}</span>
                                </div>
                                <span class="font-medium">1.2%</span>
                            </div>
                            @endforelse
                        </div>
                    </div>
                    <div class="md:col-span-2 bg-slate-800/50 backdrop-blur-sm border border-slate-700/50 rounded-3xl p-8 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-8 overflow-hidden">
                        <div class="max-w-md">
                            <h3 class="text-xl font-medium tracking-tight mb-2">{{ $pf3?->title ?? __('landing.create_workspace') }}</h3>
                            <p class="text-slate-400 text-sm mb-6">{{ $pf3?->description ?? __('landing.reg_subtitle') }}</p>
                            <a href="{{ route('central.register') }}" class="text-sm text-indigo-400 font-medium hover:text-indigo-300 flex items-center gap-1 w-fit">
                                {{ __('landing.sign_up') }} <iconify-icon icon="solar:arrow-right-linear"></iconify-icon>
                            </a>
                        </div>
                        <div class="flex gap-4 opacity-50 shrink-0">
                            <div class="w-12 h-12 rounded-2xl bg-slate-900 border border-slate-700 flex items-center justify-center">
                                <iconify-icon icon="solar:cart-large-minimalistic-linear" class="text-2xl"></iconify-icon>
                            </div>
                            <div class="w-12 h-12 rounded-2xl bg-slate-900 border border-slate-700 flex items-center justify-center -translate-y-4">
                                <iconify-icon icon="solar:wallet-linear" class="text-2xl"></iconify-icon>
                            </div>
                            <div class="w-12 h-12 rounded-2xl bg-slate-900 border border-slate-700 flex items-center justify-center">
                                <iconify-icon icon="solar:letter-linear" class="text-2xl"></iconify-icon>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        @endif

        @if(!empty($pricing['is_active']))
        @php
            $l3Plans = $pricing['plans'] ?? collect();
            $pricingHasYearly = $l3Plans->isNotEmpty() && $l3Plans->contains(fn ($p) => ($p->yearly_price ?? 0) > 0);
        @endphp
        <section id="pricing" class="py-24 px-6 bg-white relative scroll-mt-24" aria-labelledby="l3-pricing-title">
            <div class="max-w-7xl mx-auto">
                <div class="text-center mb-16">
                    @if($pricing['settings'])
                        <h2 class="text-3xl md:text-4xl font-semibold tracking-tight text-slate-900 mb-4" id="l3-pricing-title">{{ $pricing['settings']->section_title }}</h2>
                        <p class="text-slate-600 mb-8">{{ $pricing['settings']->section_subtitle }}</p>
                    @else
                        <h2 class="text-3xl md:text-4xl font-semibold tracking-tight text-slate-900 mb-4" id="l3-pricing-title">{{ __('landing.pricing') }}</h2>
                        <p class="text-slate-600 mb-8">{{ __('landing.how_it_works_subtitle') }}</p>
                    @endif
                    @if($pricingHasYearly)
                    <div class="inline-flex items-center p-1 bg-slate-100 rounded-full border border-slate-200/60" role="group" aria-label="{{ __('landing.billing_cycle') }}">
                        <button type="button" class="l3-price-tab px-6 py-2 rounded-full text-sm font-medium bg-white shadow-sm text-slate-900" data-cycle="monthly">{{ __('landing.monthly') }}</button>
                        <button type="button" class="l3-price-tab px-6 py-2 rounded-full text-sm font-medium text-slate-500 hover:text-slate-900 transition" data-cycle="yearly">{{ __('landing.yearly') }}</button>
                    </div>
                    @endif
                </div>
                @if($l3Plans->isNotEmpty())
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-5xl mx-auto items-center">
                    @foreach($l3Plans as $i => $plan)
                    @php
                        $featured = $l3Plans->count() >= 2 && $i === 1;
                        $limits = $plan->limits ?? [];
                        $planFeatures = $plan->features ?? [];
                    @endphp
                    <div class="{{ $featured ? 'bg-slate-900 border border-slate-800 rounded-3xl p-8 shadow-2xl shadow-indigo-900/20 relative md:-translate-y-4' : 'bg-white border border-slate-200 rounded-3xl p-8 shadow-sm' }}">
                        @if($featured)
                            <div class="absolute top-0 inset-x-0 flex justify-center -translate-y-1/2">
                                <span class="bg-indigo-500 text-white text-xs font-medium px-3 py-1 rounded-full uppercase tracking-wide">{{ __('landing.most_popular') }}</span>
                            </div>
                        @endif
                        <h3 class="text-lg font-medium {{ $featured ? 'text-white' : 'text-slate-900' }} mb-2">{{ $plan->name }}</h3>
                        <p class="text-sm {{ $featured ? 'text-slate-400' : 'text-slate-500' }} mb-6">
                            @if($plan->price == 0)
                                {{ __('landing.perfect_to_start') }}
                            @else
                                {{ __('landing.for_growing_teams') }}
                            @endif
                        </p>
                        <div class="mb-8 l3-plan-price" data-monthly>
                            @if($plan->price == 0 && $plan->yearly_price == 0)
                                <span class="text-4xl font-semibold tracking-tight {{ $featured ? 'text-white' : 'text-slate-900' }}">{{ __('landing.free') }}</span>
                            @else
                                <span class="text-4xl font-semibold tracking-tight {{ $featured ? 'text-white' : 'text-slate-900' }}">{{ $currencySymbol }}<span class="l3-amt-monthly">{{ $plan->price == floor($plan->price) ? number_format($plan->price, 0) : number_format($plan->price, 2) }}</span></span>
                                <span class="{{ $featured ? 'text-slate-400' : 'text-slate-500' }} text-sm">/ {{ __('landing.mo') }}</span>
                            @endif
                        </div>
                        @if($plan->yearly_price > 0)
                        <div class="mb-8 l3-plan-price hidden" data-yearly>
                            <span class="text-4xl font-semibold tracking-tight {{ $featured ? 'text-white' : 'text-slate-900' }}">{{ $currencySymbol }}<span class="l3-amt-yearly">{{ $plan->yearly_price == floor($plan->yearly_price) ? number_format($plan->yearly_price, 0) : number_format($plan->yearly_price, 2) }}</span></span>
                            <span class="{{ $featured ? 'text-slate-400' : 'text-slate-500' }} text-sm">/ {{ __('landing.yr') }}</span>
                            @if($plan->getYearlySavingsPercent() > 0)
                                <p class="text-xs text-indigo-400 mt-1">{{ __('landing.save') }} {{ $plan->getYearlySavingsPercent() }}%</p>
                            @endif
                        </div>
                        @endif
                        <a href="{{ route('central.register', ['plan' => $plan->id]) }}" class="block w-full text-center py-2.5 px-4 rounded-full text-sm font-medium transition mb-8 {{ $featured ? 'bg-indigo-500 text-white hover:bg-indigo-400 shadow-lg shadow-indigo-500/30' : 'bg-white border border-slate-200 text-slate-900 hover:bg-slate-50' }}">
                            {{ $plan->isFree() ? __('landing.get_started') : ($plan->hasTrial() ? __('landing.start_free_trial') : __('landing.choose_plan')) }}
                        </a>
                        <ul class="flex flex-col gap-3 text-sm {{ $featured ? 'text-slate-300' : 'text-slate-600' }}">
                            @foreach($limits as $key => $value)
                                @php $meta = \App\Models\Central\Plan::AVAILABLE_LIMITS[$key] ?? null; @endphp
                                @if($meta)
                                    <li class="flex items-start gap-3">
                                        <iconify-icon icon="solar:check-circle-linear" class="text-lg {{ $featured ? 'text-indigo-400' : 'text-indigo-500' }} shrink-0"></iconify-icon>
                                        <span>{{ $value == -1 ? __('landing.unlimited') : $value }} {{ $meta['label'] }}</span>
                                    </li>
                                @endif
                            @endforeach
                            @foreach($planFeatures as $fKey)
                                @php $fMeta = \App\Models\Central\Plan::AVAILABLE_FEATURES[$fKey] ?? null; @endphp
                                @if($fMeta)
                                    <li class="flex items-start gap-3">
                                        <iconify-icon icon="solar:check-circle-linear" class="text-lg {{ $featured ? 'text-indigo-400' : 'text-indigo-500' }} shrink-0"></iconify-icon>
                                        <span>{{ $fMeta['label'] }}</span>
                                    </li>
                                @endif
                            @endforeach
                        </ul>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>
        </section>
        @endif

        @if(!empty($howItWorks['is_active']))
        <section class="py-24 px-6 bg-slate-50 scroll-mt-24" id="journey" aria-labelledby="l3-journey-title">
            <div class="max-w-5xl mx-auto">
                <div class="text-center mb-16">
                    <p class="text-sm font-medium text-indigo-600 mb-2">{{ optional($howItWorks['section'])->section_label ?? __('landing.how_it_works') }}</p>
                    <h2 class="text-3xl font-semibold tracking-tight text-slate-900 mb-4" id="l3-journey-title">{{ optional($howItWorks['section'])->section_title ?? __('landing.how_it_works_title') }}</h2>
                    <p class="text-slate-600">{{ optional($howItWorks['section'])->section_subtitle ?? __('landing.how_it_works_subtitle') }}</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 relative">
                    <div class="hidden md:block absolute top-8 left-[15%] right-[15%] h-[1px] bg-gradient-to-r from-transparent via-slate-300 to-transparent"></div>
                    @forelse($howItWorks['steps'] as $i => $step)
                    <div class="relative text-center">
                        <div class="w-16 h-16 mx-auto bg-white border border-slate-200 shadow-sm rounded-full flex flex-col items-center justify-center text-xl font-semibold mb-6 relative z-10 {{ $i === $howItWorks['steps']->count() - 1 ? '!bg-slate-900 !border-slate-900 !text-white shadow-lg shadow-slate-900/20' : 'text-slate-900' }}">
                            @if($step->icon ?? null)
                                <i class="{{ $step->icon }} text-lg mb-0.5 {{ $i === $howItWorks['steps']->count() - 1 ? 'text-white' : '' }}" aria-hidden="true"></i>
                            @endif
                            <span>{{ $i + 1 }}</span>
                        </div>
                        <h3 class="text-lg font-medium tracking-tight text-slate-900 mb-2">{{ $step->title }}</h3>
                        <p class="text-sm text-slate-500 px-4">{{ $step->description }}</p>
                    </div>
                    @empty
                    @foreach([
                        ['t' => __('landing.hiw_features_title'), 'd' => __('landing.hiw_features_desc')],
                        ['t' => __('landing.hiw_pricing_title'), 'd' => __('landing.hiw_pricing_desc')],
                        ['t' => __('landing.hiw_data_title'), 'd' => __('landing.hiw_data_desc')],
                    ] as $j => $row)
                    <div class="relative text-center">
                        <div class="w-16 h-16 mx-auto {{ $j === 2 ? 'bg-slate-900 border border-slate-900 shadow-lg shadow-slate-900/20 text-white' : 'bg-white border border-slate-200 shadow-sm text-slate-900' }} rounded-full flex items-center justify-center text-xl font-semibold mb-6 relative z-10">
                            {{ $j + 1 }}
                        </div>
                        <h3 class="text-lg font-medium tracking-tight text-slate-900 mb-2">{{ $row['t'] }}</h3>
                        <p class="text-sm text-slate-500 px-4">{{ $row['d'] }}</p>
                    </div>
                    @endforeach
                    @endforelse
                </div>
            </div>
        </section>
        @endif

        @if($l3TestimonialsVisible)
        <section id="testimonials" class="py-24 px-6 bg-slate-50 scroll-mt-24" aria-labelledby="l3-testimonials-title">
            <div class="max-w-7xl mx-auto">
                <div class="text-center max-w-2xl mx-auto mb-16">
                    <p class="text-sm font-medium text-indigo-600 mb-2">{{ __('landing.testimonials') }}</p>
                    <h2 class="text-3xl md:text-4xl font-semibold tracking-tight text-slate-900 mb-4" id="l3-testimonials-title">{{ __('landing.loved_by_businesses') }}</h2>
                    <p class="text-slate-600">{{ __('landing.testimonials_subtitle') }}</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    @foreach($l3TestimonialsActive as $testimonial)
                    <article class="bg-white border border-slate-200/80 rounded-2xl p-8 shadow-sm">
                        @if($testimonial->rating)
                        <div class="flex gap-0.5 text-amber-400 mb-4" aria-label="{{ $testimonial->rating }} / 5">
                            @for($s = 1; $s <= 5; $s++)
                                <i class="bi bi-star{{ $s <= $testimonial->rating ? '-fill' : '' }}" aria-hidden="true"></i>
                            @endfor
                        </div>
                        @endif
                        <blockquote class="text-slate-700 text-sm leading-relaxed mb-6">
                            <p>“{{ $testimonial->review }}”</p>
                        </blockquote>
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center text-sm font-semibold overflow-hidden shrink-0">
                                @if($testimonial->avatar)
                                    <img src="{{ asset($testimonial->avatar) }}" alt="" class="w-full h-full object-cover" decoding="async">
                                @else
                                    {{ strtoupper(mb_substr($testimonial->client_name, 0, 1)) }}
                                @endif
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-slate-900">{{ $testimonial->client_name }}</p>
                                @if($testimonial->company_name)
                                    <p class="text-xs text-slate-500">{{ $testimonial->company_name }}</p>
                                @endif
                            </div>
                        </div>
                    </article>
                    @endforeach
                </div>
            </div>
        </section>
        @endif

        @if($l3FaqsVisible)
        <section id="faq" class="py-24 px-6 bg-white scroll-mt-24" aria-labelledby="l3-faq-title">
            <div class="max-w-3xl mx-auto">
                <p class="text-center text-sm font-medium text-indigo-600 mb-2">{{ __('landing.faq') }}</p>
                <h2 class="text-3xl font-semibold tracking-tight text-slate-900 mb-3 text-center" id="l3-faq-title">{{ __('landing.faq_title') }}</h2>
                <p class="text-slate-600 text-center text-sm mb-10">{{ __('landing.faq_subtitle') }}</p>
                <div class="flex flex-col gap-4">
                    @foreach($l3Faqs as $faq)
                    <details class="group bg-slate-50 border border-slate-200 rounded-2xl overflow-hidden [&_summary::-webkit-details-marker]:hidden">
                        <summary class="flex justify-between items-center font-medium cursor-pointer list-none p-6 text-slate-900 gap-4">
                            <span>{{ $faq->question }}</span>
                            <span class="transition shrink-0 group-open:rotate-180">
                                <iconify-icon icon="solar:alt-arrow-down-linear" class="text-xl text-slate-400"></iconify-icon>
                            </span>
                        </summary>
                        <div class="text-slate-600 text-sm px-6 pb-6 leading-relaxed border-t border-slate-100 pt-4">{!! nl2br(e($faq->answer)) !!}</div>
                    </details>
                    @endforeach
                </div>
            </div>
        </section>
        @endif

        @if($l3CtaVisible)
        <section class="py-24 px-6 relative overflow-hidden scroll-mt-24 @if($cta->background_image) bg-cover bg-center @endif" id="cta" aria-labelledby="l3-cta-title" @if($cta->background_image) style="background-image:url('{{ asset($cta->background_image) }}')" @endif>
            <div class="absolute inset-0 bg-slate-900/92 @if($cta->background_image) mix-blend-multiply @endif"></div>
            <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-full max-w-4xl h-[400px] bg-indigo-500/30 rounded-full blur-[100px] pointer-events-none"></div>
            <div class="max-w-4xl mx-auto text-center relative z-10">
                <h2 class="text-4xl md:text-5xl font-semibold tracking-tighter text-white mb-6" id="l3-cta-title">{{ $cta->title }}</h2>
                @if($cta->subtitle)
                    <p class="text-lg text-indigo-100/70 mb-10 max-w-xl mx-auto">{{ $cta->subtitle }}</p>
                @endif
                <div class="flex flex-col sm:flex-row justify-center items-center gap-4">
                    @if($cta->button_text)
                        <a href="{{ $cta->button_url ?? route('central.register') }}" class="bg-white text-slate-900 px-8 py-4 rounded-full font-medium hover:bg-slate-50 transition-all shadow-xl shadow-white/10 w-full sm:w-auto inline-flex items-center justify-center gap-2">
                            {{ $cta->button_text }}
                        </a>
                    @endif
                    <a href="{{ route('central.register') }}" class="text-white bg-transparent border border-white/20 px-8 py-4 rounded-full font-medium hover:bg-white/10 transition-all w-full sm:w-auto inline-flex items-center justify-center">
                        {{ __('landing.sign_up_free') }}
                    </a>
                </div>
            </div>
        </section>
        @endif
    </main>

    <footer class="bg-white border-t border-slate-200/50 pt-16 pb-8 px-6">
        <div class="max-w-7xl mx-auto grid grid-cols-2 md:grid-cols-5 gap-8 mb-12">
            <div class="col-span-2 md:col-span-2">
                <a href="{{ route('central.welcome') }}" class="flex items-center gap-2 mb-6">
                    <span class="w-6 h-6 rounded bg-slate-900 flex items-center justify-center text-white overflow-hidden">
                        @if($logoUrl)
                            <img src="{{ $logoUrl }}" alt="" class="w-full h-full object-cover">
                        @else
                            <iconify-icon icon="solar:box-minimalistic-linear" class="text-sm"></iconify-icon>
                        @endif
                    </span>
                    @if($generalSettings->show_site_name ?? true)
                        <span class="text-lg tracking-tighter font-semibold text-slate-900">{{ $appName }}</span>
                    @endif
                </a>
                @if($footer && $footer->footer_about)
                    <p class="text-sm text-slate-500 max-w-xs mb-6">{{ $footer->footer_about }}</p>
                @endif
                @if($footer)
                <div class="flex gap-4">
                    @foreach([
                        'facebook' => ['icon' => 'bi-facebook', 'label' => 'Facebook'],
                        'twitter' => ['icon' => 'bi-twitter-x', 'label' => 'X'],
                        'linkedin' => ['icon' => 'bi-linkedin', 'label' => 'LinkedIn'],
                        'instagram' => ['icon' => 'bi-instagram', 'label' => 'Instagram'],
                        'youtube' => ['icon' => 'bi-youtube', 'label' => 'YouTube'],
                    ] as $social => $meta)
                        @if($footer->$social)
                            <a href="{{ $footer->$social }}" target="_blank" rel="noopener noreferrer" class="text-slate-400 hover:text-slate-900 transition text-xl" aria-label="{{ $meta['label'] }}">
                                <i class="bi {{ $meta['icon'] }}" aria-hidden="true"></i>
                            </a>
                        @endif
                    @endforeach
                </div>
                @endif
            </div>
            <div>
                <h4 class="font-medium text-slate-900 mb-4 text-sm">{{ __('landing.quick_links') }}</h4>
                <ul class="flex flex-col gap-3 text-sm text-slate-500">
                    <li><a href="{{ route('central.welcome') }}" class="hover:text-slate-900 transition">{{ __('landing.home') }}</a></li>
                    @if(!empty($features['is_active']))
                        <li><a href="#features" class="hover:text-slate-900 transition">{{ __('landing.features') }}</a></li>
                    @endif
                    @if($l3PlatformVisible)
                        <li><a href="#platform" class="hover:text-slate-900 transition">Platform</a></li>
                    @endif
                    @if(!empty($pricing['is_active']))
                        <li><a href="#pricing" class="hover:text-slate-900 transition">{{ __('landing.pricing') }}</a></li>
                    @endif
                    @if(!empty($howItWorks['is_active']))
                        <li><a href="#journey" class="hover:text-slate-900 transition">{{ __('landing.how_it_works') }}</a></li>
                    @endif
                    @if($l3TestimonialsVisible)
                        <li><a href="#testimonials" class="hover:text-slate-900 transition">{{ __('landing.testimonials') }}</a></li>
                    @endif
                    @if($l3FaqsVisible)
                        <li><a href="#faq" class="hover:text-slate-900 transition">{{ __('landing.faq') }}</a></li>
                    @endif
                    <li><a href="{{ route('central.register') }}" class="hover:text-slate-900 transition">{{ __('landing.sign_up') }}</a></li>
                    @if($footer && ($footer->show_admin_login ?? false))
                        <li><a href="{{ route('central.login') }}" class="hover:text-slate-900 transition">{{ __('landing.admin_login') }}</a></li>
                    @endif
                    <li><a href="{{ route('central.privacy-policy') }}" class="hover:text-slate-900 transition">{{ __('landing.privacy_policy') }}</a></li>
                    <li><a href="{{ route('central.terms-conditions') }}" class="hover:text-slate-900 transition">{{ __('landing.terms_and_conditions') }}</a></li>
                    <li><a href="#" id="cookiePreferencesLink" class="hover:text-slate-900 transition">{{ __('landing.cookie_preferences_link') }}</a></li>
                </ul>
            </div>
            <div>
                <h4 class="font-medium text-slate-900 mb-4 text-sm">{{ __('landing.contact') }}</h4>
                @if($footer && ($footer->contact_email || $footer->contact_phone || $footer->address))
                <ul class="flex flex-col gap-3 text-sm text-slate-500">
                    @if($footer->contact_email)
                        <li><a href="mailto:{{ $footer->contact_email }}" class="hover:text-slate-900 transition">{{ $footer->contact_email }}</a></li>
                    @endif
                    @if($footer->contact_phone)
                        <li><a href="tel:{{ preg_replace('/[^0-9+]/', '', $footer->contact_phone) }}" class="hover:text-slate-900 transition">{{ $footer->contact_phone }}</a></li>
                    @endif
                    @if($footer->address)
                        <li class="text-slate-500">{{ $footer->address }}</li>
                    @endif
                </ul>
                @else
                    <p class="text-sm text-slate-500">{{ $appName }}</p>
                @endif
            </div>
        </div>
        <div class="max-w-7xl mx-auto pt-8 border-t border-slate-100 flex flex-col md:flex-row items-center justify-between gap-4">
            <p class="text-xs text-slate-400">{{ optional($footer)->copyright_text ?: ('© ' . date('Y') . ' ' . $appName . '. ' . __('landing.all_rights')) }}</p>
            <div class="flex gap-6 text-xs text-slate-400">
                <a href="{{ route('central.privacy-policy') }}" class="hover:text-slate-900 transition">{{ __('landing.privacy_policy') }}</a>
            </div>
        </div>
    </footer>

    <div class="fixed bottom-4 left-4 right-4 max-w-lg mx-auto z-[60] p-4 rounded-2xl bg-white border border-slate-200 shadow-xl shadow-slate-900/10 translate-y-[120%] opacity-0 transition-all duration-300 pointer-events-none" id="cookieConsent">
        <h4 class="text-sm font-semibold text-slate-900 mb-1">{{ __('landing.cookie_banner_title') }}</h4>
        <p class="text-xs text-slate-600 mb-3">
            {{ __('landing.cookie_banner_text') }}
            <a href="{{ route('central.privacy-policy') }}#cookies" class="text-indigo-600 hover:underline">{{ __('landing.privacy_policy') }}</a>
        </p>
        <div class="flex flex-wrap gap-2">
            <button type="button" class="px-3 py-1.5 rounded-full bg-slate-900 text-white text-xs font-medium hover:bg-slate-800" id="cookieAcceptBtn">{{ __('landing.cookie_accept_all') }}</button>
            <button type="button" class="px-3 py-1.5 rounded-full border border-slate-200 text-slate-700 text-xs font-medium hover:bg-slate-50" id="cookieRejectBtn">{{ __('landing.cookie_reject_all') }}</button>
            <button type="button" class="px-3 py-1.5 rounded-full text-indigo-600 text-xs font-medium hover:underline" id="cookieCustomizeBtn">{{ __('landing.cookie_customize') }}</button>
        </div>
        <div id="cookieCustomize" class="hidden mt-3 pt-3 border-t border-slate-100">
            <p class="text-xs text-slate-500 mb-2">{{ __('landing.cookie_analytics_desc') }}</p>
            <label class="flex items-center gap-2 text-xs text-slate-700 mb-2">
                <input type="checkbox" id="cookieAnalytics"> {{ __('landing.cookie_analytics') }}
            </label>
            <label class="flex items-center gap-2 text-xs text-slate-700 mb-2">
                <input type="checkbox" id="cookieMarketing"> {{ __('landing.cookie_marketing') }}
            </label>
            <button type="button" class="px-3 py-1.5 rounded-full bg-indigo-600 text-white text-xs font-medium hover:bg-indigo-500" id="cookieSaveBtn">{{ __('landing.cookie_save_preferences') }}</button>
        </div>
    </div>

    <script src="{{ asset('assets_super/js/landing-three.js') }}"></script>
</body>
</html>
