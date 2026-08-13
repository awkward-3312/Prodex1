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
    <link href="{{ asset('assets_super/css/manrope.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/landing-six-page.css') }}" rel="stylesheet">
    @include('central.partials.landing-font')
</head>
@php
    $l6HeroVisible = $hero && ($hero->is_active ?? true);
    $l6CtaVisible = $cta && ($cta->is_active ?? true);
    $l6Faqs = isset($faqs) ? $faqs->filter(fn ($f) => ($f->is_active ?? true)) : collect();
    $l6FaqsVisible = $l6Faqs->isNotEmpty();
    $l6TestimonialsActive = isset($testimonials) ? $testimonials->filter(fn ($t) => ($t->is_active ?? true)) : collect();
    $l6TestimonialsVisible = $l6TestimonialsActive->isNotEmpty();
    $l6FeatureItems = $features['items'] ?? collect();
@endphp
<body class="bg-[#F3F8F5] text-[#0A2640] antialiased overflow-x-hidden">

    <header class="fixed top-0 inset-x-0 z-50 bg-white/95 backdrop-blur-md border-b border-black/5" id="l6Header">
        <div class="max-w-6xl mx-auto px-5 h-[72px] flex items-center justify-between gap-4">
            <a href="{{ route('central.welcome') }}" class="flex items-center gap-2 shrink-0">
                @if($logoUrl)
                    <img src="{{ $logoUrl }}" alt="" class="h-9 w-auto rounded-lg object-contain">
                @else
                    <span class="h-9 w-9 rounded-xl bg-[#0A2640] text-[#65CE89] flex items-center justify-center font-extrabold text-sm">{{ strtoupper(substr($appName, 0, 1)) }}</span>
                @endif
                @if($generalSettings->show_site_name ?? true)
                    <span class="font-bold text-lg tracking-tight text-[#0A2640]">{{ $appName }}</span>
                @endif
            </a>
            <nav class="hidden lg:flex items-center gap-10 text-[15px] font-medium text-[#0A2640]/70" aria-label="{{ __('landing.quick_links') }}">
                @if(!empty($features['is_active']))
                    <a href="#features" class="hover:text-[#0A2640] transition-colors">{{ __('landing.features') }}</a>
                @endif
                @if(!empty($pricing['is_active']))
                    <a href="#pricing" class="hover:text-[#0A2640] transition-colors">{{ __('landing.pricing') }}</a>
                @endif
                @if(!empty($howItWorks['is_active']))
                    <a href="#journey" class="hover:text-[#0A2640] transition-colors">{{ __('landing.how_it_works') }}</a>
                @endif
                @if($l6TestimonialsVisible)
                    <a href="#testimonials" class="hover:text-[#0A2640] transition-colors">{{ __('landing.testimonials') }}</a>
                @endif
                @if($l6FaqsVisible)
                    <a href="#faq" class="hover:text-[#0A2640] transition-colors">{{ __('landing.faq') }}</a>
                @endif
            </nav>
            <div class="flex items-center gap-3">
                @if(isset($languages) && $languages->count() > 1)
                <div class="relative hidden sm:block" id="l6Lang">
                    <button type="button" class="flex items-center gap-1 text-sm font-semibold text-[#0A2640]/70 hover:text-[#0A2640] px-2 py-1 rounded-lg" id="l6LangBtn" aria-expanded="false" aria-haspopup="true">
                        <i class="bi bi-globe2" aria-hidden="true"></i>
                        {{ strtoupper($currentLocale ?? app()->getLocale()) }}
                    </button>
                    <div class="hidden absolute end-0 mt-2 min-w-[10rem] py-2 bg-white border border-black/10 rounded-2xl shadow-xl z-50" id="l6LangMenu" role="menu">
                        @foreach($languages as $lang)
                        <form method="POST" action="{{ route('central.locale', $lang->locale) }}">
                            @csrf
                            <button type="submit" class="w-full text-start px-4 py-2 text-sm hover:bg-[#F3F8F5] flex items-center gap-2 {{ ($currentLocale ?? app()->getLocale()) === $lang->locale ? 'text-[#65CE89] font-semibold' : '' }}" role="menuitem">
                                @if($lang->flag)
                                    <img src="{{ asset('flags/' . $lang->flag) }}" alt="" class="w-5 rounded-sm">
                                @endif
                                {{ $lang->name }}
                            </button>
                        </form>
                        @endforeach
                    </div>
                </div>
                @endif
                @if($footer && ($footer->show_admin_login ?? false))
                    <a href="{{ route('central.login') }}" class="hidden md:inline text-sm font-semibold text-[#0A2640]/80 hover:text-[#0A2640]">{{ __('landing.admin_login') }}</a>
                @endif
                <a href="{{ route('central.register') }}" class="hidden sm:inline-flex items-center justify-center rounded-full bg-[#65CE89] text-[#0A2640] px-6 py-2.5 text-sm font-bold hover:bg-[#7ad89a] transition-colors shadow-sm shadow-[#65CE89]/25">{{ __('landing.sign_up_free') }}</a>
                <button type="button" class="lg:hidden p-2 rounded-xl text-[#0A2640]" id="l6OpenMenu" aria-expanded="false" aria-controls="l6Drawer" aria-label="Menu">
                    <i class="bi bi-list text-2xl" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </header>

    <div class="fixed inset-0 z-40 lg:hidden hidden" id="l6Drawer" aria-hidden="true">
        <div class="absolute inset-0 bg-[#0A2640]/40 backdrop-blur-sm" data-l6-drawer-backdrop></div>
        <div class="absolute top-0 end-0 h-full w-[min(20rem,100%)] bg-white shadow-2xl flex flex-col p-6" id="l6DrawerPanel" role="dialog" aria-modal="true" aria-label="Menu">
            <div class="flex justify-end mb-6">
                <button type="button" class="p-2 rounded-xl hover:bg-[#F3F8F5]" id="l6CloseMenu" aria-label="Close"><i class="bi bi-x-lg"></i></button>
            </div>
            <nav class="flex flex-col gap-1 font-semibold text-[#0A2640]">
                @if(!empty($features['is_active']))<a href="#features" class="py-3 px-3 rounded-xl hover:bg-[#F3F8F5]">{{ __('landing.features') }}</a>@endif
                @if(!empty($pricing['is_active']))<a href="#pricing" class="py-3 px-3 rounded-xl hover:bg-[#F3F8F5]">{{ __('landing.pricing') }}</a>@endif
                @if(!empty($howItWorks['is_active']))<a href="#journey" class="py-3 px-3 rounded-xl hover:bg-[#F3F8F5]">{{ __('landing.how_it_works') }}</a>@endif
                @if($l6TestimonialsVisible)<a href="#testimonials" class="py-3 px-3 rounded-xl hover:bg-[#F3F8F5]">{{ __('landing.testimonials') }}</a>@endif
                @if($l6FaqsVisible)<a href="#faq" class="py-3 px-3 rounded-xl hover:bg-[#F3F8F5]">{{ __('landing.faq') }}</a>@endif
            </nav>
            <div class="mt-auto pt-8 flex flex-col gap-3 border-t border-black/5">
                @if($footer && ($footer->show_admin_login ?? false))
                    <a href="{{ route('central.login') }}" class="py-3 text-center rounded-full border-2 border-[#0A2640] font-semibold">{{ __('landing.admin_login') }}</a>
                @endif
                <a href="{{ route('central.register') }}" class="py-3 text-center rounded-full bg-[#65CE89] text-[#0A2640] font-bold">{{ __('landing.sign_up_free') }}</a>
            </div>
        </div>
    </div>

    <main id="main">
        @if($l6HeroVisible)
        <section class="relative pt-28 pb-16 md:pt-36 md:pb-24 px-5 overflow-hidden" id="top" @if($hero->background_image) style="background-image:url('{{ asset($hero->background_image) }}');background-size:cover;background-position:center" @endif>
            @if($hero->background_image)
            <div class="absolute inset-0 bg-[#F3F8F5]/92"></div>
            @endif
            <div class="relative z-[1] max-w-6xl mx-auto">
                <div class="text-center {{ $hero->hero_image ? '' : 'max-w-3xl mx-auto' }}">
                    @if($hero->subtitle)
                        <p class="inline-flex items-center gap-2 text-sm font-semibold text-[#65CE89] mb-6 tracking-wide uppercase">{{ $hero->subtitle }}</p>
                    @endif
                    <h1 class="text-4xl md:text-5xl lg:text-[3.25rem] font-extrabold text-[#0A2640] leading-[1.12] tracking-tight mb-6" id="l6-hero-title">
                        @if($hero->title){!! $hero->title !!}@else{{ __('landing.launch_title') }}@endif
                    </h1>
                    @if($hero->description)
                        <p class="text-lg text-[#0A2640]/70 leading-relaxed mb-10 max-w-2xl mx-auto">{{ $hero->description }}</p>
                    @endif
                    <div class="flex flex-wrap justify-center gap-4">
                        @if($hero->primary_button_text)
                            <a href="{{ $hero->primary_button_url ?? route('central.register') }}" class="inline-flex items-center justify-center rounded-full bg-[#65CE89] text-[#0A2640] px-8 py-3.5 text-[15px] font-bold hover:bg-[#7ad89a] transition-colors shadow-lg shadow-[#65CE89]/30">{{ $hero->primary_button_text }}</a>
                        @else
                            <a href="{{ route('central.register') }}" class="inline-flex items-center justify-center rounded-full bg-[#65CE89] text-[#0A2640] px-8 py-3.5 text-[15px] font-bold hover:bg-[#7ad89a] transition-colors shadow-lg shadow-[#65CE89]/30">{{ __('landing.start_free_trial') }}</a>
                        @endif
                        @if($hero->secondary_button_text)
                            <a href="{{ $hero->secondary_button_url ?? '#features' }}" class="inline-flex items-center justify-center rounded-full border-2 border-[#0A2640] text-[#0A2640] px-8 py-3.5 text-[15px] font-bold hover:bg-[#0A2640] hover:text-white transition-colors">{{ $hero->secondary_button_text }}</a>
                        @else
                            <a href="#features" class="inline-flex items-center justify-center rounded-full border-2 border-[#0A2640] text-[#0A2640] px-8 py-3.5 text-[15px] font-bold hover:bg-[#0A2640] hover:text-white transition-colors">{{ __('landing.features') }}</a>
                        @endif
                    </div>
                    @if(session('success') && session('tenant_url'))
                        <div class="mt-8 mx-auto max-w-md p-4 rounded-2xl bg-white border border-[#65CE89]/40 text-sm" role="status">
                            {{ session('message') }}<br>
                            <a href="{{ session('tenant_url') }}" class="font-semibold text-[#0A2640] underline">{{ session('tenant_url') }}</a>
                        </div>
                    @endif
                </div>
                @if($hero->hero_image)
                <div class="relative mt-14 max-w-5xl mx-auto">
                    <div class="absolute -inset-4 bg-gradient-to-b from-[#65CE89]/20 to-transparent rounded-[2rem] blur-2xl -z-10"></div>
                    <div class="rounded-[2rem] overflow-hidden shadow-2xl shadow-[#0A2640]/10 border border-white/80 bg-white">
                        <img src="{{ asset($hero->hero_image) }}" alt="{{ strip_tags($hero->title ?? $appName) }}" class="w-full h-auto object-cover" decoding="async">
                    </div>
                </div>
                @endif
            </div>
        </section>
        @else
        <div class="h-20 md:h-24" aria-hidden="true"></div>
        @endif

        @if(isset($stats) && $stats->isNotEmpty())
        <section class="py-10 border-y border-black/5 bg-white/80" aria-labelledby="l6-stats">
            <div class="max-w-6xl mx-auto px-5">
                <p class="text-center text-xs font-bold uppercase tracking-widest text-[#65CE89] mb-6" id="l6-stats">{{ __('landing.trust_bar_eyebrow') }}</p>
                <div class="flex flex-wrap justify-center gap-x-14 gap-y-8">
                    @foreach($stats as $stat)
                        <div class="text-center min-w-[120px]">
                            <p class="text-3xl md:text-4xl font-extrabold text-[#0A2640]">{{ $stat->value }}</p>
                            <p class="text-sm font-medium text-[#0A2640]/55 mt-1">{{ $stat->label }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
        @endif

        @if(!empty($features['is_active']) && $l6FeatureItems->isNotEmpty())
        <section id="features" class="py-20 md:py-28 px-5 scroll-mt-24">
            <div class="max-w-6xl mx-auto">
                <div class="text-center max-w-2xl mx-auto mb-14 md:mb-16">
                    <p class="text-sm font-bold text-[#65CE89] uppercase tracking-widest mb-3">{{ __('landing.features') }}</p>
                    @if($features['section'])
                        <h2 class="text-3xl md:text-[2.25rem] font-extrabold text-[#0A2640] tracking-tight mb-4">{{ $features['section']->section_title }}</h2>
                        <p class="text-[#0A2640]/65 text-lg leading-relaxed">{{ $features['section']->section_subtitle }}</p>
                    @else
                        <h2 class="text-3xl md:text-[2.25rem] font-extrabold text-[#0A2640] tracking-tight">{{ __('landing.features') }}</h2>
                    @endif
                </div>
                <div class="grid md:grid-cols-3 gap-8">
                    @foreach($l6FeatureItems as $feature)
                    <article class="bg-white rounded-[1.75rem] p-8 md:p-10 shadow-[0_8px_40px_rgba(10,38,64,0.06)] border border-black/[0.04] hover:shadow-[0_16px_48px_rgba(10,38,64,0.1)] transition-shadow">
                        <div class="w-14 h-14 rounded-2xl bg-[#F3F8F5] flex items-center justify-center mb-6">
                            @if($feature->image)
                                <img src="{{ asset($feature->image) }}" alt="" class="w-8 h-8 object-contain">
                            @elseif($feature->icon)
                                <i class="{{ $feature->icon }} text-2xl text-[#0A2640]"></i>
                            @else
                                <i class="bi bi-stars text-2xl text-[#65CE89]"></i>
                            @endif
                        </div>
                        <h3 class="text-xl font-bold text-[#0A2640] mb-3">{{ $feature->title }}</h3>
                        @if($feature->description)
                            <p class="text-[#0A2640]/65 text-[15px] leading-relaxed">{{ $feature->description }}</p>
                        @endif
                    </article>
                    @endforeach
                </div>
            </div>
        </section>
        @endif

        @if(!empty($howItWorks['is_active']))
        <section id="journey" class="py-20 md:py-28 px-5 bg-[#0A2640] text-white scroll-mt-24">
            <div class="max-w-6xl mx-auto grid lg:grid-cols-2 gap-12 lg:gap-20 items-center">
                <div>
                    <h2 class="text-3xl md:text-[2.35rem] font-extrabold leading-tight tracking-tight mb-8">{{ optional($howItWorks['section'])->section_title ?? __('landing.how_it_works_title') }}</h2>
                    <ul class="space-y-5 mb-10">
                        @forelse($howItWorks['steps'] as $step)
                            <li class="flex gap-4 items-start">
                                <span class="mt-1 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-[#65CE89]/20 text-[#65CE89]"><i class="bi bi-check-lg text-sm"></i></span>
                                <span class="text-white/90 text-lg font-medium">{{ $step->title }}</span>
                            </li>
                        @empty
                            @foreach([__('landing.hiw_features_title'), __('landing.hiw_data_title'), __('landing.hiw_trial_title')] as $line)
                            <li class="flex gap-4 items-start">
                                <span class="mt-1 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-[#65CE89]/20 text-[#65CE89]"><i class="bi bi-check-lg text-sm"></i></span>
                                <span class="text-white/90 text-lg font-medium">{{ $line }}</span>
                            </li>
                            @endforeach
                        @endforelse
                    </ul>
                    <a href="{{ route('central.register') }}" class="inline-flex items-center justify-center rounded-full bg-[#65CE89] text-[#0A2640] px-10 py-4 text-[15px] font-bold hover:bg-[#7ad89a] transition-colors">{{ __('landing.get_started') }}</a>
                </div>
                <div class="rounded-[2rem] bg-white/5 border border-white/10 p-8 md:p-12 backdrop-blur-sm">
                    <p class="text-[#65CE89] text-sm font-bold uppercase tracking-widest mb-4">{{ optional($howItWorks['section'])->section_label ?? __('landing.how_it_works') }}</p>
                    <p class="text-white/75 text-lg leading-relaxed">{{ optional($howItWorks['section'])->section_subtitle ?? __('landing.how_it_works_subtitle') }}</p>
                </div>
            </div>
        </section>
        @endif

        @if($l6TestimonialsVisible)
        <section id="testimonials" class="py-20 md:py-28 px-5 bg-white scroll-mt-24">
            <div class="max-w-6xl mx-auto text-center mb-14">
                <p class="text-sm font-bold text-[#65CE89] uppercase tracking-widest mb-3">{{ __('landing.testimonials') }}</p>
                <h2 class="text-3xl md:text-[2.25rem] font-extrabold text-[#0A2640] tracking-tight">{{ __('landing.loved_by_businesses') }}</h2>
                <p class="text-[#0A2640]/60 mt-4 max-w-xl mx-auto">{{ __('landing.testimonials_subtitle') }}</p>
            </div>
            <div class="max-w-6xl mx-auto grid md:grid-cols-2 gap-8">
                @foreach($l6TestimonialsActive as $t)
                <article class="rounded-[1.75rem] border border-black/5 bg-[#F3F8F5]/50 p-8 md:p-10 text-left">
                    @if($t->rating)
                    <div class="flex gap-1 text-[#65CE89] mb-6">
                        @for($s = 1; $s <= 5; $s++)
                            <i class="bi bi-star{{ $s <= $t->rating ? '-fill' : '' }}"></i>
                        @endfor
                    </div>
                    @endif
                    <h3 class="text-xl md:text-2xl font-bold text-[#0A2640] leading-snug mb-8">“{{ \Illuminate\Support\Str::limit($t->review, 160) }}”</h3>
                    <div class="flex items-center gap-4">
                        <div class="h-14 w-14 rounded-full bg-[#0A2640] text-[#65CE89] flex items-center justify-center font-bold text-lg overflow-hidden shrink-0">
                            @if($t->avatar)
                                <img src="{{ asset($t->avatar) }}" alt="" class="w-full h-full object-cover">
                            @else
                                {{ strtoupper(mb_substr($t->client_name, 0, 1)) }}
                            @endif
                        </div>
                        <div>
                            <p class="font-bold text-[#0A2640]">{{ $t->client_name }}</p>
                            @if($t->company_name)<p class="text-sm text-[#0A2640]/55">{{ $t->company_name }}</p>@endif
                        </div>
                    </div>
                </article>
                @endforeach
            </div>
        </section>
        @endif

        @if(!empty($pricing['is_active']))
        @php
            $l6Plans = $pricing['plans'] ?? collect();
            $l6PricingYearly = $l6Plans->isNotEmpty() && $l6Plans->contains(fn ($p) => ($p->yearly_price ?? 0) > 0);
        @endphp
        <section id="pricing" class="py-20 md:py-28 px-5 bg-[#F3F8F5] scroll-mt-24">
            <div class="max-w-6xl mx-auto">
                <div class="text-center mb-14">
                    @if($pricing['settings'])
                        <h2 class="text-3xl md:text-[2.25rem] font-extrabold text-[#0A2640] tracking-tight mb-4" id="l6-pricing-title">{{ $pricing['settings']->section_title }}</h2>
                        <p class="text-[#0A2640]/65 max-w-xl mx-auto">{{ $pricing['settings']->section_subtitle }}</p>
                    @else
                        <h2 class="text-3xl md:text-[2.25rem] font-extrabold text-[#0A2640] tracking-tight mb-4" id="l6-pricing-title">{{ __('landing.pricing') }}</h2>
                    @endif
                    @if($l6PricingYearly)
                    <div class="inline-flex items-center p-1 bg-white rounded-full border border-black/10 mt-8 shadow-sm l6-price-tab-wrap" role="group" aria-label="{{ __('landing.billing_cycle') }}">
                        <button type="button" class="l6-price-tab is-active px-6 py-2 rounded-full text-sm font-bold" data-cycle="monthly">{{ __('landing.monthly') }}</button>
                        <button type="button" class="l6-price-tab px-6 py-2 rounded-full text-sm font-bold" data-cycle="yearly">{{ __('landing.yearly') }}</button>
                    </div>
                    @endif
                </div>
                @if($l6Plans->isNotEmpty())
                <div class="grid md:grid-cols-3 gap-8 items-stretch">
                    @foreach($l6Plans as $i => $plan)
                    @php
                        $featured = $l6Plans->count() >= 2 && $i === 1;
                        $limits = $plan->limits ?? [];
                        $planFeatures = $plan->features ?? [];
                    @endphp
                    <div class="flex flex-col rounded-[1.75rem] p-8 md:p-10 border {{ $featured ? 'bg-[#0A2640] text-white border-[#0A2640] shadow-2xl scale-[1.02] z-10' : 'bg-white border-black/5 shadow-lg' }}">
                        @if($featured)
                            <p class="text-xs font-bold uppercase tracking-widest text-[#65CE89] mb-4">{{ __('landing.most_popular') }}</p>
                        @endif
                        <h3 class="text-xl font-bold mb-2 {{ $featured ? 'text-white' : 'text-[#0A2640]' }}">{{ $plan->name }}</h3>
                        <div class="mb-6 l6-plan-price" data-monthly>
                            @if($plan->price == 0 && $plan->yearly_price == 0)
                                <span class="text-4xl font-extrabold {{ $featured ? 'text-white' : 'text-[#0A2640]' }}">{{ __('landing.free') }}</span>
                            @else
                                <span class="text-4xl font-extrabold {{ $featured ? 'text-white' : 'text-[#0A2640]' }}">{{ $currencySymbol }}<span class="l6-amt-monthly">{{ $plan->price == floor($plan->price) ? number_format($plan->price, 0) : number_format($plan->price, 2) }}</span></span>
                                <span class="text-sm {{ $featured ? 'text-white/60' : 'text-[#0A2640]/50' }}">/ {{ __('landing.mo') }}</span>
                            @endif
                        </div>
                        @if($plan->yearly_price > 0)
                        <div class="mb-6 l6-plan-price hidden" data-yearly>
                            <span class="text-4xl font-extrabold {{ $featured ? 'text-white' : 'text-[#0A2640]' }}">{{ $currencySymbol }}<span class="l6-amt-yearly">{{ $plan->yearly_price == floor($plan->yearly_price) ? number_format($plan->yearly_price, 0) : number_format($plan->yearly_price, 2) }}</span></span>
                            <span class="text-sm {{ $featured ? 'text-white/60' : 'text-[#0A2640]/50' }}">/ {{ __('landing.yr') }}</span>
                        </div>
                        @endif
                        <a href="{{ route('central.register', ['plan' => $plan->id]) }}" class="mb-8 inline-flex justify-center items-center rounded-full py-3 px-6 text-sm font-bold transition-colors {{ $featured ? 'bg-[#65CE89] text-[#0A2640] hover:bg-[#7ad89a]' : 'border-2 border-[#0A2640] text-[#0A2640] hover:bg-[#0A2640] hover:text-white' }}">
                            {{ $plan->isFree() ? __('landing.get_started') : ($plan->hasTrial() ? __('landing.start_free_trial') : __('landing.choose_plan')) }}
                        </a>
                        <ul class="space-y-3 text-sm {{ $featured ? 'text-white/85' : 'text-[#0A2640]/70' }} flex-1">
                            @foreach($limits as $key => $value)
                                @php $meta = \App\Models\Central\Plan::AVAILABLE_LIMITS[$key] ?? null; @endphp
                                @if($meta)
                                    <li class="flex gap-2"><i class="bi bi-check-lg text-[#65CE89] shrink-0"></i> {{ $value == -1 ? __('landing.unlimited') : $value }} {{ $meta['label'] }}</li>
                                @endif
                            @endforeach
                            @foreach($planFeatures as $fKey)
                                @php $fMeta = \App\Models\Central\Plan::AVAILABLE_FEATURES[$fKey] ?? null; @endphp
                                @if($fMeta)
                                    <li class="flex gap-2"><i class="bi bi-check-lg text-[#65CE89] shrink-0"></i> {{ $fMeta['label'] }}</li>
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

        @if($l6FaqsVisible)
        <section id="faq" class="py-20 md:py-28 px-5 bg-white scroll-mt-24">
            <div class="max-w-3xl mx-auto">
                <p class="text-center text-sm font-bold text-[#65CE89] uppercase tracking-widest mb-3">{{ __('landing.faq') }}</p>
                <h2 class="text-3xl font-extrabold text-[#0A2640] text-center mb-4">{{ __('landing.faq_title') }}</h2>
                <p class="text-center text-[#0A2640]/60 text-sm mb-12">{{ __('landing.faq_subtitle') }}</p>
                <div class="space-y-3">
                    @foreach($l6Faqs as $faq)
                    <details class="group rounded-2xl border border-black/5 bg-[#F3F8F5]/40 overflow-hidden [&_summary::-webkit-details-marker]:hidden">
                        <summary class="flex justify-between items-center cursor-pointer list-none p-5 md:p-6 font-bold text-[#0A2640] gap-4">
                            <span>{{ $faq->question }}</span>
                            <i class="bi bi-chevron-down shrink-0 transition-transform group-open:rotate-180 text-[#65CE89]"></i>
                        </summary>
                        <div class="px-5 md:px-6 pb-6 text-[#0A2640]/70 text-[15px] leading-relaxed border-t border-black/5 pt-4">{!! nl2br(e($faq->answer)) !!}</div>
                    </details>
                    @endforeach
                </div>
            </div>
        </section>
        @endif

        @if($l6CtaVisible)
        <section id="cta" class="py-20 md:py-28 px-5 scroll-mt-24 relative overflow-hidden @if($cta->background_image) bg-cover bg-center @endif" @if($cta->background_image) style="background-image:url('{{ asset($cta->background_image) }}')" @endif>
            <div class="absolute inset-0 bg-[#0A2640]/95 @if($cta->background_image) mix-blend-multiply @endif"></div>
            <div class="relative z-10 max-w-3xl mx-auto text-center">
                <h2 class="text-3xl md:text-5xl font-extrabold text-white tracking-tight mb-6" id="l6-cta-title">{{ $cta->title }}</h2>
                @if($cta->subtitle)<p class="text-lg text-white/75 mb-10">{{ $cta->subtitle }}</p>@endif
                <div class="flex flex-wrap justify-center gap-4">
                    @if($cta->button_text)
                        <a href="{{ $cta->button_url ?? route('central.register') }}" class="inline-flex items-center justify-center rounded-full bg-[#65CE89] text-[#0A2640] px-10 py-4 text-[15px] font-bold hover:bg-[#7ad89a]">{{ $cta->button_text }}</a>
                    @endif
                    <a href="{{ route('central.register') }}" class="inline-flex items-center justify-center rounded-full border-2 border-white/40 text-white px-10 py-4 text-[15px] font-bold hover:bg-white/10">{{ __('landing.sign_up_free') }}</a>
                </div>
            </div>
        </section>
        @endif
    </main>

    <footer class="bg-[#0A2640] text-white pt-16 pb-10 px-5">
        <div class="max-w-6xl mx-auto grid grid-cols-2 md:grid-cols-4 gap-10 mb-12">
            <div class="col-span-2 md:col-span-1">
                @if($generalSettings->show_site_name ?? true)
                    <p class="font-bold text-lg mb-4">{{ $appName }}</p>
                @endif
                @if($footer && $footer->footer_about)
                    <p class="text-sm text-white/65 leading-relaxed">{{ $footer->footer_about }}</p>
                @endif
                @if($footer)
                <div class="flex gap-4 mt-6">
                    @foreach(['facebook' => 'bi-facebook', 'twitter' => 'bi-twitter-x', 'linkedin' => 'bi-linkedin', 'instagram' => 'bi-instagram', 'youtube' => 'bi-youtube'] as $soc => $ic)
                        @if($footer->$soc)
                            <a href="{{ $footer->$soc }}" target="_blank" rel="noopener noreferrer" class="text-white/50 hover:text-[#65CE89] text-xl"><i class="bi {{ $ic }}"></i></a>
                        @endif
                    @endforeach
                </div>
                @endif
            </div>
            <div>
                <p class="font-bold text-sm mb-4 text-[#65CE89]">{{ __('landing.quick_links') }}</p>
                <ul class="space-y-3 text-sm text-white/70">
                    <li><a href="{{ route('central.welcome') }}" class="hover:text-white">{{ __('landing.home') }}</a></li>
                    @if(!empty($features['is_active']))<li><a href="#features" class="hover:text-white">{{ __('landing.features') }}</a></li>@endif
                    @if(!empty($pricing['is_active']))<li><a href="#pricing" class="hover:text-white">{{ __('landing.pricing') }}</a></li>@endif
                    <li><a href="{{ route('central.register') }}" class="hover:text-white">{{ __('landing.sign_up') }}</a></li>
                    @if($footer && ($footer->show_admin_login ?? false))<li><a href="{{ route('central.login') }}" class="hover:text-white">{{ __('landing.admin_login') }}</a></li>@endif
                    <li><a href="{{ route('central.privacy-policy') }}" class="hover:text-white">{{ __('landing.privacy_policy') }}</a></li>
                    <li><a href="{{ route('central.terms-conditions') }}" class="hover:text-white">{{ __('landing.terms_and_conditions') }}</a></li>
                    <li><a href="#" id="cookiePreferencesLink" class="hover:text-white">{{ __('landing.cookie_preferences_link') }}</a></li>
                </ul>
            </div>
            <div class="col-span-2 md:col-span-2">
                <p class="font-bold text-sm mb-4 text-[#65CE89]">{{ __('landing.contact') }}</p>
                @if($footer && ($footer->contact_email || $footer->contact_phone || $footer->address))
                    <ul class="space-y-2 text-sm text-white/70">
                        @if($footer->contact_email)<li><a href="mailto:{{ $footer->contact_email }}" class="hover:text-[#65CE89]">{{ $footer->contact_email }}</a></li>@endif
                        @if($footer->contact_phone)<li><a href="tel:{{ preg_replace('/[^0-9+]/', '', $footer->contact_phone) }}" class="hover:text-[#65CE89]">{{ $footer->contact_phone }}</a></li>@endif
                        @if($footer->address)<li class="text-white/60">{{ $footer->address }}</li>@endif
                    </ul>
                @endif
            </div>
        </div>
        <div class="max-w-6xl mx-auto pt-8 border-t border-white/10 flex flex-col md:flex-row justify-between gap-4 text-xs text-white/45">
            <p>{{ optional($footer)->copyright_text ?: ('© ' . date('Y') . ' ' . $appName . '. ' . __('landing.all_rights')) }}</p>
            <p>{{ __('landing.privacy_policy') }} · <a href="{{ route('central.privacy-policy') }}" class="hover:text-[#65CE89]">{{ __('landing.privacy_policy') }}</a></p>
        </div>
    </footer>

    <div class="fixed bottom-4 left-4 right-4 max-w-lg mx-auto z-[60] p-4 rounded-2xl bg-white border border-black/10 shadow-2xl translate-y-[120%] opacity-0 transition-all duration-300 pointer-events-none" id="cookieConsent">
        <h4 class="text-sm font-bold text-[#0A2640] mb-1">{{ __('landing.cookie_banner_title') }}</h4>
        <p class="text-xs text-[#0A2640]/70 mb-3">{{ __('landing.cookie_banner_text') }} <a href="{{ route('central.privacy-policy') }}#cookies" class="text-[#0A2640] font-semibold underline">{{ __('landing.privacy_policy') }}</a></p>
        <div class="flex flex-wrap gap-2">
            <button type="button" class="px-3 py-1.5 rounded-full bg-[#0A2640] text-white text-xs font-bold" id="cookieAcceptBtn">{{ __('landing.cookie_accept_all') }}</button>
            <button type="button" class="px-3 py-1.5 rounded-full border border-black/15 text-xs font-semibold" id="cookieRejectBtn">{{ __('landing.cookie_reject_all') }}</button>
            <button type="button" class="px-3 py-1.5 text-xs font-semibold text-[#65CE89]" id="cookieCustomizeBtn">{{ __('landing.cookie_customize') }}</button>
        </div>
        <div id="cookieCustomize" class="hidden mt-3 pt-3 border-t border-black/10">
            <label class="flex items-center gap-2 text-xs mb-2"><input type="checkbox" id="cookieAnalytics"> {{ __('landing.cookie_analytics') }}</label>
            <label class="flex items-center gap-2 text-xs mb-2"><input type="checkbox" id="cookieMarketing"> {{ __('landing.cookie_marketing') }}</label>
            <button type="button" class="px-3 py-1.5 rounded-full bg-[#65CE89] text-[#0A2640] text-xs font-bold" id="cookieSaveBtn">{{ __('landing.cookie_save_preferences') }}</button>
        </div>
    </div>

    <script src="{{ asset('assets_super/js/landing-six.js') }}"></script>
</body>
</html>
