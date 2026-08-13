@php
    $generalSettings = \App\Models\Central\GeneralSetting::instance();
    $appName = $generalSettings->app_name ?: 'Stocky';
    $logoUrl = $generalSettings->getLogoUrl();
    $faviconUrl = $generalSettings->getFaviconUrl();
    $assetVersion = time();
    $appVersion = is_file(base_path('version.txt')) ? trim(@file_get_contents(base_path('version.txt'))) : null;
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Super Admin') — {{ $appName }}</title>
    <link rel="icon" href="{{ $faviconUrl ?: asset('images/super/settings/favicon.ico') }}?v={{ $assetVersion }}" type="image/x-icon">
    <link rel="shortcut icon" href="{{ $faviconUrl ?: asset('images/super/settings/favicon.ico') }}?v={{ $assetVersion }}">
    <link href="{{ asset('assets_super/css/plus-jakarta-sans.css') }}?v={{ $assetVersion }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/bootstrap.min.css') }}?v={{ $assetVersion }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/bootstrap-icons.min.css') }}?v={{ $assetVersion }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/central-shared.css') }}?v={{ $assetVersion }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/super-layout.css') }}?v={{ $assetVersion }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/super-customizer.css') }}?v={{ $assetVersion }}" rel="stylesheet">
    @stack('styles')
</head>
<body>
    <script>if(localStorage.getItem('super_theme')==='dark')document.body.classList.add('dark-mode');</script>
    <script>
        // Pre-apply saved primary color before paint to avoid color flash
        (function () {
            try {
                var saved = localStorage.getItem('super_primary_color');
                if (!saved || !/^#([0-9a-f]{6})$/i.test(saved)) return;
                var hex = saved.replace('#', '');
                var r = parseInt(hex.substring(0, 2), 16);
                var g = parseInt(hex.substring(2, 4), 16);
                var b = parseInt(hex.substring(4, 6), 16);
                function clamp(v){return Math.max(0,Math.min(255,Math.round(v)));}
                function darken(c, amt){return clamp(c*(1-amt));}
                var dr = darken(r, 0.15), dg = darken(g, 0.15), db = darken(b, 0.15);
                var darkHex = '#' + [dr, dg, db].map(function(v){var s=v.toString(16);return s.length===1?'0'+s:s;}).join('');
                var style = document.createElement('style');
                style.id = 'super-customizer-vars';
                style.textContent = ':root{'
                    + '--color-primary:' + saved + ';'
                    + '--color-primary-dark:' + darkHex + ';'
                    + '--color-primary-light:rgba(' + r + ',' + g + ',' + b + ',0.10);'
                    + '--color-primary-lighter:rgba(' + r + ',' + g + ',' + b + ',0.05);'
                    + '--bs-link-color-rgb:' + r + ',' + g + ',' + b + ';'
                    + '}'
                    + 'body.dark-mode{'
                    + '--color-primary:' + saved + ';'
                    + '--color-primary-dark:' + darkHex + ';'
                    + '--color-primary-light:rgba(' + r + ',' + g + ',' + b + ',0.14);'
                    + '--color-primary-lighter:rgba(' + r + ',' + g + ',' + b + ',0.07);'
                    + '--bs-link-color-rgb:' + r + ',' + g + ',' + b + ';'
                    + '}';
                document.documentElement.appendChild(style);
            } catch (e) {}
        })();
    </script>
    <div class="page-loader" id="pageLoader">
        <div class="loader-dots">
            <span></span><span></span><span></span>
        </div>
    </div>

    <div class="app-wrap">
        <div class="sidebar-overlay" id="sidebarOverlay"></div>
        <aside class="sidebar" id="sidebar">
            <a class="sidebar-brand" href="{{ route('super.dashboard') }}">
                @if($logoUrl)
                    <img src="{{ $logoUrl }}?v={{ $assetVersion }}" alt="{{ $appName }}" class="brand-logo">
                @else
                    <span class="brand-icon">{{ strtoupper(substr($appName, 0, 1)) }}</span>
                @endif
                @if($generalSettings->show_site_name ?? true)
                    <span class="brand-text">{{ $appName }}</span>
                @endif
            </a>
            @php $centralUser = Auth::guard('central')->user(); @endphp
            <nav class="sidebar-nav">
                <div class="sidebar-section-label">{{ __('super.sidebar.main') }}</div>
                @if($centralUser->hasPermission('dashboard'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.dashboard') ? 'active' : '' }}" href="{{ route('super.dashboard') }}" data-title="{{ __('super.sidebar.dashboard') }}">
                        <i class="bi bi-grid-1x2-fill"></i>
                        <span>{{ __('super.sidebar.dashboard') }}</span>
                    </a>
                </div>
                @endif

                @if($centralUser->hasAnyPermission(['tenants', 'plans', 'subscriptions', 'reminders', 'admins']))
                <div class="sidebar-section-label">{{ __('super.sidebar.management') }}</div>
                @if($centralUser->hasPermission('tenants'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.tenants.*') ? 'active' : '' }}" href="{{ route('super.tenants.index') }}" data-title="{{ __('super.sidebar.tenants') }}">
                        <i class="bi bi-building"></i>
                        <span>{{ __('super.sidebar.tenants') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('plans'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.plans.*') ? 'active' : '' }}" href="{{ route('super.plans.index') }}" data-title="{{ __('super.sidebar.plans') }}">
                        <i class="bi bi-tag-fill"></i>
                        <span>{{ __('super.sidebar.plans') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('subscriptions'))
                <div class="nav-item">
                    <a class="nav-link {{ (request()->routeIs('super.subscriptions.*') && ! request()->routeIs('super.subscriptions.reminders')) ? 'active' : '' }}" href="{{ route('super.subscriptions.index') }}" data-title="{{ __('super.sidebar.subscriptions') }}">
                        <i class="bi bi-credit-card-2-front"></i>
                        <span>{{ __('super.sidebar.subscriptions') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('reminders'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.subscriptions.reminders') ? 'active' : '' }}" href="{{ route('super.subscriptions.reminders') }}" data-title="{{ __('super.sidebar.reminders') }}">
                        <i class="bi bi-alarm"></i>
                        <span>{{ __('super.sidebar.reminders') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('admins'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.admins.*') ? 'active' : '' }}" href="{{ route('super.admins.index') }}" data-title="{{ __('super.sidebar.admins') }}">
                        <i class="bi bi-person-lock"></i>
                        <span>{{ __('super.sidebar.admins') }}</span>
                    </a>
                </div>
                @endif
                @endif

                @if($centralUser->hasPermission('payments'))
                <div class="sidebar-section-label">{{ __('super.sidebar.payments') }}</div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.payments.dashboard') ? 'active' : '' }}" href="{{ route('super.payments.dashboard') }}" data-title="{{ __('super.sidebar.overview') }}">
                        <i class="bi bi-bar-chart-line"></i>
                        <span>{{ __('super.sidebar.overview') }}</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.payments.index') || request()->routeIs('super.payments.show') ? 'active' : '' }}" href="{{ route('super.payments.index') }}" data-title="{{ __('super.sidebar.transactions') }}">
                        <i class="bi bi-receipt"></i>
                        <span>{{ __('super.sidebar.transactions') }}</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.payments.pending-registrations*') ? 'active' : '' }}" href="{{ route('super.payments.pending-registrations') }}" data-title="{{ __('super.sidebar.pending_payments') }}">
                        <i class="bi bi-hourglass-split"></i>
                        <span>{{ __('super.sidebar.pending_payments') }}</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.payments.invoices') ? 'active' : '' }}" href="{{ route('super.payments.invoices') }}" data-title="{{ __('super.sidebar.invoices') }}">
                        <i class="bi bi-file-earmark-text"></i>
                        <span>{{ __('super.sidebar.invoices') }}</span>
                    </a>
                </div>
                @endif

                @if($centralUser->hasPermission('support'))
                @php $supportUnread = \App\Models\Central\SupportTicket::where('admin_unread', true)->count(); @endphp
                <div class="sidebar-section-label">{{ __('super.sidebar.support') }}</div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.support.*') ? 'active' : '' }}" href="{{ route('super.support.index') }}" data-title="{{ __('super.sidebar.support_tickets') }}">
                        <i class="bi bi-life-preserver"></i>
                        <span>{{ __('super.sidebar.support_tickets') }}</span>
                        @if($supportUnread > 0)
                            <span class="badge badge-no ms-auto">{{ $supportUnread }}</span>
                        @endif
                    </a>
                </div>
                @endif

                @if($centralUser->hasPermission('reports'))
                <div class="sidebar-section-label">{{ __('super.sidebar.reports') }}</div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.reports.*') ? 'active' : '' }}" href="{{ route('super.reports.index') }}" data-title="{{ __('super.sidebar.reports') }}">
                        <i class="bi bi-bar-chart-fill"></i>
                        <span>{{ __('super.sidebar.reports') }}</span>
                    </a>
                </div>
                @endif

                @if($centralUser->hasPermission('cms'))
                <div class="sidebar-section-label">{{ __('super.sidebar.cms') }}</div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.cms.index') ? 'active' : '' }}" href="{{ route('super.cms.index') }}" data-title="{{ __('super.sidebar.landing_page') }}">
                        <i class="bi bi-window-stack"></i>
                        <span>{{ __('super.sidebar.landing_page') }}</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.cms.landing-pages*') ? 'active' : '' }}" href="{{ route('super.cms.landing-pages') }}" data-title="{{ __('super.sidebar.landing_pages') }}">
                        <i class="bi bi-layout-wtf"></i>
                        <span>{{ __('super.sidebar.landing_pages') }}</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.cms.hero*') ? 'active' : '' }}" href="{{ route('super.cms.hero') }}" data-title="{{ __('super.sidebar.hero_section') }}">
                        <i class="bi bi-stars"></i>
                        <span>{{ __('super.sidebar.hero_section') }}</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.cms.features*') ? 'active' : '' }}" href="{{ route('super.cms.features') }}" data-title="{{ __('super.sidebar.features') }}">
                        <i class="bi bi-grid-3x3-gap"></i>
                        <span>{{ __('super.sidebar.features') }}</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.cms.pricing*') ? 'active' : '' }}" href="{{ route('super.cms.pricing') }}" data-title="{{ __('super.sidebar.pricing_section') }}">
                        <i class="bi bi-currency-dollar"></i>
                        <span>{{ __('super.sidebar.pricing_section') }}</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.cms.stats*') ? 'active' : '' }}" href="{{ route('super.cms.stats') }}" data-title="{{ __('super.sidebar.stats') }}">
                        <i class="bi bi-bar-chart"></i>
                        <span>{{ __('super.sidebar.stats') }}</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.cms.how-it-works*') ? 'active' : '' }}" href="{{ route('super.cms.how-it-works') }}" data-title="{{ __('super.sidebar.how_it_works') }}">
                        <i class="bi bi-diagram-3"></i>
                        <span>{{ __('super.sidebar.how_it_works') }}</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.cms.testimonials*') ? 'active' : '' }}" href="{{ route('super.cms.testimonials') }}" data-title="{{ __('super.sidebar.testimonials') }}">
                        <i class="bi bi-chat-quote"></i>
                        <span>{{ __('super.sidebar.testimonials') }}</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.cms.faq*') ? 'active' : '' }}" href="{{ route('super.cms.faqs') }}" data-title="{{ __('super.sidebar.faq') }}">
                        <i class="bi bi-question-circle"></i>
                        <span>{{ __('super.sidebar.faq') }}</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.cms.cta*') ? 'active' : '' }}" href="{{ route('super.cms.cta') }}" data-title="{{ __('super.sidebar.cta_section') }}">
                        <i class="bi bi-megaphone"></i>
                        <span>{{ __('super.sidebar.cta_section') }}</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.cms.footer*') ? 'active' : '' }}" href="{{ route('super.cms.footer') }}" data-title="{{ __('super.sidebar.footer') }}">
                        <i class="bi bi-layout-text-window-reverse"></i>
                        <span>{{ __('super.sidebar.footer') }}</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.cms.seo*') ? 'active' : '' }}" href="{{ route('super.cms.seo') }}" data-title="{{ __('super.sidebar.seo_settings') }}">
                        <i class="bi bi-search"></i>
                        <span>{{ __('super.sidebar.seo_settings') }}</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.cms.privacy-policy*') ? 'active' : '' }}" href="{{ route('super.cms.privacy-policy') }}" data-title="{{ __('super.sidebar.privacy_policy') }}">
                        <i class="bi bi-shield-check"></i>
                        <span>{{ __('super.sidebar.privacy_policy') }}</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.cms.terms-conditions*') ? 'active' : '' }}" href="{{ route('super.cms.terms-conditions') }}" data-title="{{ __('super.sidebar.terms') }}">
                        <i class="bi bi-file-earmark-text"></i>
                        <span>{{ __('super.sidebar.terms') }}</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.cms.translations*') ? 'active' : '' }}" href="{{ route('super.cms.translations') }}" data-title="{{ __('super.sidebar.translations') }}">
                        <i class="bi bi-translate"></i>
                        <span>{{ __('super.sidebar.translations') }}</span>
                    </a>
                </div>
                @endif

                @if($centralUser->hasPermission('knowledge_base'))
                <div class="sidebar-section-label">{{ __('super.sidebar.knowledge_base') }}</div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.knowledge-base.*') ? 'active' : '' }}" href="{{ route('super.knowledge-base.index') }}" data-title="{{ __('super.sidebar.knowledge_base') }}">
                        <i class="bi bi-book"></i>
                        <span>{{ __('super.sidebar.knowledge_base') }}</span>
                    </a>
                </div>
                @endif

                @if($centralUser->hasPermission('how_it_works'))
                <div class="sidebar-section-label">{{ __('super.sidebar.help') }}</div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.how-it-works') ? 'active' : '' }}" href="{{ route('super.how-it-works') }}" data-title="{{ __('super.sidebar.how_it_works') }}">
                        <i class="bi bi-diagram-3"></i>
                        <span>{{ __('super.sidebar.how_it_works') }}</span>
                    </a>
                </div>
                @endif

                @if($centralUser->hasPermission('logs_health'))
                <div class="sidebar-section-label">{{ __('super.sidebar.monitoring') }}</div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.logs-health.*') ? 'active' : '' }}" href="{{ route('super.logs-health.index') }}" data-title="{{ __('super.sidebar.logs_health') }}">
                        <i class="bi bi-activity"></i>
                        <span>{{ __('super.sidebar.logs_health') }}</span>
                    </a>
                </div>
                @endif

                @if($centralUser->hasAnyPermission(['settings', 'email_templates', 'sms_templates', 'whatsapp']))
                <div class="sidebar-section-label">{{ __('super.sidebar.settings') }}</div>
                @if($centralUser->hasPermission('settings'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.settings.general*') ? 'active' : '' }}" href="{{ route('super.settings.general') }}" data-title="{{ __('super.sidebar.general_settings') }}">
                        <i class="bi bi-sliders"></i>
                        <span>{{ __('super.sidebar.general_settings') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('email_templates'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.email-templates*') ? 'active' : '' }}" href="{{ route('super.email-templates.index') }}" data-title="{{ __('super.sidebar.email_templates') }}">
                        <i class="bi bi-envelope-paper"></i>
                        <span>{{ __('super.sidebar.email_templates') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('sms_templates'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.sms-templates*') ? 'active' : '' }}" href="{{ route('super.sms-templates.index') }}" data-title="{{ __('super.sidebar.sms_templates') }}">
                        <i class="bi bi-chat-left-text"></i>
                        <span>{{ __('super.sidebar.sms_templates') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('settings'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.settings.mail*') ? 'active' : '' }}" href="{{ route('super.settings.mail') }}" data-title="{{ __('super.sidebar.mail_settings') }}">
                        <i class="bi bi-mailbox"></i>
                        <span>{{ __('super.sidebar.mail_settings') }}</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.settings.sms*') ? 'active' : '' }}" href="{{ route('super.settings.sms') }}" data-title="{{ __('super.sidebar.sms_settings') }}">
                        <i class="bi bi-chat-left-dots"></i>
                        <span>{{ __('super.sidebar.sms_settings') }}</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.settings.payment-gateways*') ? 'active' : '' }}" href="{{ route('super.settings.payment-gateways') }}" data-title="{{ __('super.sidebar.payment_gateways') }}">
                        <i class="bi bi-gear"></i>
                        <span>{{ __('super.sidebar.payment_gateways') }}</span>
                    </a>
                </div>
                @endif
                @if($centralUser->hasPermission('whatsapp'))
                <div class="nav-item">
                    <a class="nav-link {{ request()->routeIs('super.settings.whatsapp*') ? 'active' : '' }}" href="{{ route('super.settings.whatsapp') }}" data-title="{{ __('super.sidebar.whatsapp_settings') }}">
                        <i class="bi bi-whatsapp"></i>
                        <span>{{ __('super.sidebar.whatsapp_settings') }}</span>
                    </a>
                </div>
                @endif
                @endif
            </nav>
            <div class="sidebar-footer-actions">
                <form method="POST" action="{{ route('central.logout') }}" class="mb-0">
                    @csrf
                    <button type="submit" class="btn-logout">
                        <i class="bi bi-box-arrow-left"></i>
                        <span>{{ __('super.sidebar.log_out') }}</span>
                    </button>
                </form>
            </div>
        </aside>

        <div class="main-content">
            <header class="topbar">
                <button type="button" class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
                    <i class="bi bi-layout-sidebar-inset"></i>
                </button>
                <div class="topbar-right ms-auto">
                    {{-- Language switcher --}}
                    @php
                        $currentLocale = app()->getLocale();
                        $flagSvgs = [
                            'en' => '<svg viewBox="0 0 60 30"><clipPath id="s"><path d="M0,0 v30 h60 v-30 z"/></clipPath><clipPath id="t"><path d="M30,15 h30 v15 z v15 h-30 z h-30 v-15 z v-15 h30 z"/></clipPath><g clip-path="url(#s)"><path d="M0,0 v30 h60 v-30 z" fill="#012169"/><path d="M0,0 L60,30 M60,0 L0,30" stroke="#fff" stroke-width="6"/><path d="M0,0 L60,30 M60,0 L0,30" clip-path="url(#t)" stroke="#C8102E" stroke-width="4"/><path d="M30,0 v30 M0,15 h60" stroke="#fff" stroke-width="10"/><path d="M30,0 v30 M0,15 h60" stroke="#C8102E" stroke-width="6"/></g></svg>',
                            'fr' => '<svg viewBox="0 0 3 2"><rect width="3" height="2" fill="#ED2939"/><rect width="2" height="2" fill="#fff"/><rect width="1" height="2" fill="#002395"/></svg>',
                            'ar' => '<svg viewBox="0 0 12 8"><rect width="12" height="8" fill="#006C35"/><rect y="0" width="12" height="2.67" fill="#006C35"/><rect y="2.67" width="12" height="2.67" fill="#fff"/><rect y="5.33" width="12" height="2.67" fill="#000"/></svg>',
                            'es' => '<svg viewBox="0 0 3 2"><rect width="3" height="2" fill="#c60b1e"/><rect y=".5" width="3" height="1" fill="#ffc400"/></svg>',
                            'hi' => '<svg viewBox="0 0 900 600"><rect width="900" height="200" fill="#FF9933"/><rect y="200" width="900" height="200" fill="#fff"/><rect y="400" width="900" height="200" fill="#138808"/><circle cx="450" cy="300" r="60" fill="#000080"/><circle cx="450" cy="300" r="54" fill="#fff"/><circle cx="450" cy="300" r="18" fill="#000080"/></svg>',
                            'bn' => '<svg viewBox="0 0 5 3"><rect width="5" height="3" fill="#006a4e"/><circle cx="2.25" cy="1.5" r="0.9" fill="#f42a41"/></svg>',
                            'tr' => '<svg viewBox="0 0 12 8"><rect width="12" height="8" fill="#E30A17"/><circle cx="4.4" cy="4" r="2" fill="#fff"/><circle cx="4.9" cy="4" r="1.6" fill="#E30A17"/><polygon points="5.8,4 6.6,3.2 5.9,3.8 6.8,3.8 6,3.2" fill="#fff" transform="rotate(18 6.2 4)"/></svg>',
                            'de' => '<svg viewBox="0 0 5 3"><rect width="5" height="3" fill="#FFCE00"/><rect width="5" height="2" fill="#DD0000"/><rect width="5" height="1" fill="#000"/></svg>',
                            'pt' => '<svg viewBox="0 0 6 4"><rect width="6" height="4" fill="#FF0000"/><rect width="2.4" height="4" fill="#006600"/><circle cx="2.4" cy="2" r="0.8" fill="#FFCC00"/></svg>',
                            'ur' => '<svg viewBox="0 0 12 8"><rect width="12" height="8" fill="#01411c"/><rect width="3" height="8" fill="#fff"/><circle cx="7.6" cy="4.2" r="2.3" fill="#fff"/><circle cx="8.15" cy="3.65" r="1.95" fill="#01411c"/><polygon points="9.2,1.9 9.35,2.3 9.77,2.31 9.44,2.58 9.55,2.99 9.2,2.75 8.85,2.99 8.96,2.58 8.63,2.31 9.05,2.3" fill="#fff"/></svg>',
                        ];
                        $langLabels = [
                            'en' => 'English',
                            'fr' => 'Français',
                            'ar' => 'العربية',
                            'es' => 'Español',
                            'hi' => 'हिन्दी',
                            'bn' => 'বাংলা',
                            'tr' => 'Türkçe',
                            'de' => 'Deutsch',
                            'pt' => 'Português',
                            'ur' => 'اردو',
                        ];
                        $switcherLanguages = \App\Models\Central\CentralLanguage::active();
                    @endphp
                    <div class="dropdown">
                        <button type="button" class="lang-switcher-btn" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Language">
                            <span class="lang-flag-icon">{!! $flagSvgs[$currentLocale] ?? '' !!}</span>
                            <span class="lang-code">{{ strtoupper($currentLocale) }}</span>
                            <i class="bi bi-chevron-down lang-chevron"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end lang-dropdown">
                            <div class="lang-dropdown-title">{{ __('landing.language') }}</div>
                            @foreach($switcherLanguages as $lang)
                            @php $code = $lang->locale; @endphp
                            <form method="POST" action="{{ route('super.locale', $code) }}" class="mb-0">
                                @csrf
                                <button type="submit" class="lang-option {{ $currentLocale === $code ? 'active' : '' }}">
                                    <span class="lang-option-flag">{!! $flagSvgs[$code] ?? '' !!}</span>
                                    <span class="lang-option-label">{{ $langLabels[$code] ?? $lang->name }}</span>
                                </button>
                            </form>
                            @endforeach
                        </div>
                    </div>

                    {{-- Dark mode toggle --}}
                    <button type="button" class="topbar-icon-btn" id="themeToggle" aria-label="Toggle dark mode">
                        <i class="bi bi-moon-stars-fill"></i>
                        <i class="bi bi-sun-fill"></i>
                    </button>

                    <div class="dropdown">
                        <button type="button" class="topbar-icon-btn" id="quickActionsDropdown" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('super.topbar.quick_actions') }}">
                            <i class="bi bi-lightning-charge-fill"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><span class="dropdown-header">{{ __('super.topbar.quick_actions') }}</span></li>
                            <li><a class="dropdown-item" href="{{ route('super.tenants.index') }}"><i class="bi bi-building"></i> {{ __('super.topbar.tenants') }}</a></li>
                            <li><a class="dropdown-item" href="{{ route('super.plans.create') }}"><i class="bi bi-plus-lg"></i> {{ __('super.topbar.new_plan') }}</a></li>
                            <li><a class="dropdown-item" href="{{ route('super.subscriptions.index') }}"><i class="bi bi-credit-card"></i> {{ __('super.topbar.subscriptions') }}</a></li>
                            <li><a class="dropdown-item" href="{{ route('super.cms.index') }}"><i class="bi bi-window-stack"></i> {{ __('super.topbar.cms_landing') }}</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="{{ route('central.welcome') }}" target="_blank"><i class="bi bi-box-arrow-up-right"></i> {{ __('super.topbar.landing_page') }}</a></li>
                        </ul>
                    </div>
                    @php
                        $__navPendingTenants = isset($pendingTenants) ? $pendingTenants : \App\Tenant::with('domains')->where('status', \App\Tenant::STATUS_PENDING)->orderByDesc('created_at')->get();
                        $__navPendingOffline = \App\Models\Central\PendingRegistration::where('gateway', 'offline')
                            ->where('status', \App\Models\Central\PendingRegistration::STATUS_PROCESSING)
                            ->with('plan')
                            ->orderByDesc('created_at')
                            ->get();
                        $__navPendingBilling = \App\Models\Central\TenantBillingPayment::whereIn('gateway', ['offline', 'manual'])
                            ->where('status', \App\Models\Central\TenantBillingPayment::STATUS_PENDING)
                            ->with(['tenant', 'plan'])
                            ->orderByDesc('created_at')
                            ->get();
                        $__navHasNotifications = $__navPendingTenants->count() > 0 || $__navPendingOffline->count() > 0 || $__navPendingBilling->count() > 0;
                    @endphp
                    <div class="dropdown">
                        <button type="button" class="topbar-icon-btn" id="notificationsDropdown" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('super.topbar.notifications') }}">
                            <i class="bi bi-bell"></i>
                            @if($__navHasNotifications)
                                <span class="badge-dot"></span>
                            @endif
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end notifications-dropdown">
                            <li><span class="dropdown-header">{{ __('super.topbar.notifications') }}</span></li>

                            {{-- Pending offline payments --}}
                            @foreach($__navPendingOffline as $reg)
                                <li>
                                    <a class="notification-item d-block text-decoration-none text-dark" href="{{ route('super.payments.pending-registrations') }}">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge badge-bank-transfer">Bank Transfer</span>
                                            <strong class="text-truncate notification-name-truncate">{{ $reg->company_name }}</strong>
                                        </div>
                                        <div class="small mt-1">Offline payment proof awaiting verification</div>
                                        <div class="small text-muted">{{ $reg->created_at->diffForHumans() }}</div>
                                    </a>
                                </li>
                            @endforeach

                            {{-- Pending billing offline payments (tenant upgrades/renewals) --}}
                            @foreach($__navPendingBilling as $billingPayment)
                                <li>
                                    <a class="notification-item d-block text-decoration-none text-dark" href="{{ route('super.payments.index', ['status' => 'pending', 'gateway' => $billingPayment->gateway]) }}">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge badge-bank-transfer">Bank Transfer</span>
                                            <strong class="text-truncate notification-name-truncate">{{ $billingPayment->tenant->company_name ?? $billingPayment->tenant_id }}</strong>
                                        </div>
                                        <div class="small mt-1">Billing payment proof awaiting verification — {{ $billingPayment->plan->name ?? 'N/A' }} ({{ ucfirst($billingPayment->billing_cycle) }})</div>
                                        <div class="small text-muted">{{ $billingPayment->created_at->diffForHumans() }}</div>
                                    </a>
                                </li>
                            @endforeach

                            {{-- Pending tenants --}}
                            @foreach($__navPendingTenants as $tenant)
                                <li>
                                    <a class="notification-item d-block text-decoration-none text-dark" href="{{ route('super.tenants.index', ['status' => 'pending']) }}">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge bg-warning text-dark badge-pending">{{ __('super.topbar.pending') }}</span>
                                            <strong class="text-truncate notification-name-truncate">{{ $tenant->company_name ?? $tenant->id }}</strong>
                                        </div>
                                        <div class="small mt-1">{{ __('super.topbar.pending_tenant_msg') }}</div>
                                        <div class="small text-muted">{{ $tenant->created_at->diffForHumans() }}</div>
                                    </a>
                                </li>
                            @endforeach

                            @if(!$__navHasNotifications)
                                <li>
                                    <div class="notification-item">
                                        <span>{{ __('super.topbar.no_notifications') }}</span>
                                        <div class="small mt-1">{{ __('super.topbar.all_caught_up') }}</div>
                                    </div>
                                </li>
                            @endif

                            @if($__navPendingOffline->count() || $__navPendingBilling->count())
                                <li><hr class="dropdown-divider"></li>
                                @if($__navPendingOffline->count())
                                <li>
                                    <a class="dropdown-item text-center small" href="{{ route('super.payments.pending-registrations') }}">
                                        View {{ $__navPendingOffline->count() }} pending {{ Str::plural('registration', $__navPendingOffline->count()) }}
                                    </a>
                                </li>
                                @endif
                                @if($__navPendingBilling->count())
                                <li>
                                    <a class="dropdown-item text-center small" href="{{ route('super.payments.index', ['status' => 'pending', 'gateway' => 'offline']) }}">
                                        View {{ $__navPendingBilling->count() }} pending billing {{ Str::plural('payment', $__navPendingBilling->count()) }}
                                    </a>
                                </li>
                                @endif
                            @endif
                            @if($__navPendingTenants->count())
                                <li>@if(!$__navPendingOffline->count())<hr class="dropdown-divider">@endif</li>
                                <li>
                                    <a class="dropdown-item text-center small" href="{{ route('super.tenants.index', ['status' => 'pending']) }}">
                                        {{ __('super.topbar.view_all_pending') }}
                                    </a>
                                </li>
                            @endif
                        </ul>
                    </div>
                    @php $user = auth()->guard('central')->user(); @endphp
                    <div class="dropdown">
                        <button type="button" class="topbar-profile-btn" id="profileDropdown" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Profile menu">
                            @if($user && $user->avatar)
                                <img src="{{ asset($user->avatar) }}" alt="" class="avatar-img-round">
                            @else
                                <span class="avatar">{{ $user ? strtoupper(mb_substr($user->name ?? 'A', 0, 1)) : 'A' }}</span>
                            @endif
                            <span class="name d-none d-md-inline">{{ $user->name ?? 'Super Admin' }}</span>
                            <i class="bi bi-chevron-down text-muted small"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <div class="px-3 py-2">
                                    <p class="mb-0 fw-600">{{ $user->name ?? 'Super Admin' }}</p>
                                    <p class="mb-0 small text-muted">{{ $user->email ?? '' }}</p>
                                </div>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="{{ route('super.dashboard') }}"><i class="bi bi-grid-1x2"></i> {{ __('super.topbar.dashboard') }}</a></li>
                            <li><a class="dropdown-item" href="{{ route('super.profile') }}"><i class="bi bi-person"></i> {{ __('super.topbar.my_profile') }}</a></li>
                            <li><a class="dropdown-item" href="{{ route('central.welcome') }}" target="_blank"><i class="bi bi-house"></i> {{ __('super.topbar.home') }}</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="POST" action="{{ route('central.logout') }}" class="mb-0">
                                    @csrf
                                    <button type="submit" class="dropdown-item text-danger w-100 border-0 bg-transparent"><i class="bi bi-box-arrow-left"></i> {{ __('super.topbar.log_out') }}</button>
                                </form>
                            </li>
                        </ul>
                    </div>
                </div>
            </header>

            <main class="main-body">
                @if(session('success'))
                    <div class="alert alert-success">{{ session('success') }}</div>
                @endif
                @if(session('error'))
                    <div class="alert alert-error">{{ session('error') }}</div>
                @endif
                @if($errors->any())
                    <div class="alert alert-error">{{ $errors->first() }}</div>
                @endif
                @yield('content')
            </main>
            @if(!empty($generalSettings->dashboard_footer_text) || !empty($appVersion))
                <footer class="dashboard-footer">
                    @if(!empty($generalSettings->dashboard_footer_text))
                        <span>{!! e($generalSettings->dashboard_footer_text) !!}</span>
                    @endif
                    @if(!empty($appVersion))
                        <span class="dashboard-footer-version">
                            {{ __('super.general_settings.version') }} {{ $appVersion }}
                        </span>
                    @endif
                </footer>
            @endif
        </div>
    </div>

    <script src="{{ asset('assets_super/js/bootstrap.bundle.min.js') }}?v={{ $assetVersion }}"></script>
    <script src="{{ asset('assets_super/js/sweetalert2.all.min.js') }}?v={{ $assetVersion }}"></script>
    <script src="{{ asset('assets_super/js/super-layout.js') }}?v={{ $assetVersion }}"></script>
    @stack('scripts')

    {{-- ====================================================== --}}
    {{-- Primary Color Customizer (floating button + drawer)    --}}
    {{-- ====================================================== --}}
    @if($generalSettings->show_customizer_button ?? true)
    <button type="button" class="super-customizer-fab" id="superCustomizerFab" aria-label="Customize theme color" title="Customize theme color">
        <i class="bi bi-palette-fill"></i>
    </button>
    <div class="super-customizer-backdrop" id="superCustomizerBackdrop"></div>
    <aside class="super-customizer-drawer" id="superCustomizerDrawer" role="dialog" aria-labelledby="superCustomizerTitle" aria-hidden="true">
        <div class="super-customizer-header">
            <h3 id="superCustomizerTitle"><i class="bi bi-palette-fill"></i> Theme Customizer</h3>
            <button type="button" class="super-customizer-close" id="superCustomizerClose" aria-label="Close customizer">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="super-customizer-body">
            <div class="super-customizer-section">
                <p class="super-customizer-section-title">Primary Color</p>
                <p class="super-customizer-section-help">Pick a preset or choose any custom color. Changes apply instantly and are remembered on this browser.</p>
                <div class="super-customizer-swatches" id="superCustomizerSwatches">
                    <button type="button" class="super-customizer-swatch" data-color="#018A00" style="background:#018A00" aria-label="Green"></button>
                    <button type="button" class="super-customizer-swatch" data-color="#6366f1" style="background:#6366f1" aria-label="Indigo"></button>
                    <button type="button" class="super-customizer-swatch" data-color="#3b82f6" style="background:#3b82f6" aria-label="Blue"></button>
                    <button type="button" class="super-customizer-swatch" data-color="#06b6d4" style="background:#06b6d4" aria-label="Cyan"></button>
                    <button type="button" class="super-customizer-swatch" data-color="#14b8a6" style="background:#14b8a6" aria-label="Teal"></button>
                    <button type="button" class="super-customizer-swatch" data-color="#8b5cf6" style="background:#8b5cf6" aria-label="Purple"></button>
                    <button type="button" class="super-customizer-swatch" data-color="#ec4899" style="background:#ec4899" aria-label="Pink"></button>
                    <button type="button" class="super-customizer-swatch" data-color="#ef4444" style="background:#ef4444" aria-label="Red"></button>
                    <button type="button" class="super-customizer-swatch" data-color="#f97316" style="background:#f97316" aria-label="Orange"></button>
                    <button type="button" class="super-customizer-swatch" data-color="#f59e0b" style="background:#f59e0b" aria-label="Amber"></button>
                    <button type="button" class="super-customizer-swatch" data-color="#0f172a" style="background:#0f172a" aria-label="Slate"></button>
                    <button type="button" class="super-customizer-swatch" data-color="#64748b" style="background:#64748b" aria-label="Gray"></button>
                </div>
            </div>

            <div class="super-customizer-section">
                <p class="super-customizer-section-title">Custom Color</p>
                <div class="super-customizer-picker-row">
                    <input type="color" id="superCustomizerColorInput" value="#018A00" aria-label="Pick a custom color">
                    <input type="text" id="superCustomizerHexInput" value="#018A00" maxlength="7" aria-label="Hex color value">
                </div>
                <div class="super-customizer-actions">
                    <button type="button" class="super-customizer-btn super-customizer-btn-secondary" id="superCustomizerReset">Reset to default</button>
                </div>
            </div>
        </div>
    </aside>

    <script src="{{ asset('assets_super/js/super-customizer.js') }}?v={{ $assetVersion }}"></script>
    @endif
</body>
</html>
