@extends('central.super.layout')

@section('title', __('super.cms.title'))

@section('content')
<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
        <h1>{{ __('super.cms.title') }}</h1>
        <p class="page-subtitle">{{ __('super.cms.subtitle') }}</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('super.cms.landing-pages') }}" class="btn btn-primary">
            <i class="bi bi-layout-wtf"></i> {{ __('super.cms.landing_pages_card') }}
        </a>
        <a href="{{ route('central.welcome') }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary">
            <i class="bi bi-box-arrow-up-right"></i> {{ __('super.cms.preview') }}
        </a>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-6 col-lg-4 cms-card-col">
        <a href="{{ route('super.cms.landing-pages') }}" class="content-card d-block text-decoration-none cms-card color-inherit">
            <div class="card-body-custom">
                <div class="d-flex align-items-center gap-3">
                    <div class="icon-box icon-box-48 cms-icon-landing-pages">
                        <i class="bi bi-layout-wtf text-white fs-xl"></i>
                    </div>
                    <div>
                        <h3 class="mb-0 fw-700 fs-base">{{ __('super.cms.landing_pages_card') }}</h3>
                        <p class="mb-0 text-muted small">{{ __('super.cms.landing_pages_desc') }}</p>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-6 col-lg-4 cms-card-col">
        <a href="{{ route('super.cms.hero') }}" class="content-card d-block text-decoration-none cms-card color-inherit">
            <div class="card-body-custom">
                <div class="d-flex align-items-center gap-3">
                    <div class="icon-box icon-box-48 cms-icon-hero">
                        <i class="bi bi-stars text-white fs-xl"></i>
                    </div>
                    <div>
                        <h3 class="mb-0 fw-700 fs-base">{{ __('super.cms.hero_title') }}</h3>
                        <p class="mb-0 text-muted small">{{ __('super.cms.hero_desc') }}</p>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-6 col-lg-4">
        <a href="{{ route('super.cms.features') }}" class="content-card d-block text-decoration-none cms-card color-inherit">
            <div class="card-body-custom">
                <div class="d-flex align-items-center gap-3">
                    <div class="icon-box icon-box-48 cms-icon-features">
                        <i class="bi bi-grid-3x3-gap text-white fs-xl"></i>
                    </div>
                    <div>
                        <h3 class="mb-0 fw-700 fs-base">{{ __('super.cms.features_title') }}</h3>
                        <p class="mb-0 text-muted small">{{ __('super.cms.features_desc') }}</p>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-6 col-lg-4">
        <a href="{{ route('super.cms.pricing') }}" class="content-card d-block text-decoration-none cms-card color-inherit">
            <div class="card-body-custom">
                <div class="d-flex align-items-center gap-3">
                    <div class="icon-box icon-box-48 cms-icon-pricing">
                        <i class="bi bi-currency-dollar text-white fs-xl"></i>
                    </div>
                    <div>
                        <h3 class="mb-0 fw-700 fs-base">{{ __('super.cms.pricing_title') }}</h3>
                        <p class="mb-0 text-muted small">{{ __('super.cms.pricing_desc') }}</p>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-6 col-lg-4">
        <a href="{{ route('super.cms.stats') }}" class="content-card d-block text-decoration-none cms-card color-inherit">
            <div class="card-body-custom">
                <div class="d-flex align-items-center gap-3">
                    <div class="icon-box icon-box-48 cms-icon-stats">
                        <i class="bi bi-bar-chart text-white fs-xl"></i>
                    </div>
                    <div>
                        <h3 class="mb-0 fw-700 fs-base">{{ __('super.cms.stats_title') }}</h3>
                        <p class="mb-0 text-muted small">{{ __('super.cms.stats_desc') }}</p>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-6 col-lg-4">
        <a href="{{ route('super.cms.how-it-works') }}" class="content-card d-block text-decoration-none cms-card color-inherit">
            <div class="card-body-custom">
                <div class="d-flex align-items-center gap-3">
                    <div class="icon-box icon-box-48 cms-icon-hiw">
                        <i class="bi bi-diagram-3 text-white fs-xl"></i>
                    </div>
                    <div>
                        <h3 class="mb-0 fw-700 fs-base">{{ __('super.cms.how_it_works_title') }}</h3>
                        <p class="mb-0 text-muted small">{{ __('super.cms.how_it_works_desc') }}</p>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-6 col-lg-4">
        <a href="{{ route('super.cms.testimonials') }}" class="content-card d-block text-decoration-none cms-card color-inherit">
            <div class="card-body-custom">
                <div class="d-flex align-items-center gap-3">
                    <div class="icon-box icon-box-48 cms-icon-testimonials">
                        <i class="bi bi-chat-quote text-white fs-xl"></i>
                    </div>
                    <div>
                        <h3 class="mb-0 fw-700 fs-base">{{ __('super.cms.testimonials_title') }}</h3>
                        <p class="mb-0 text-muted small">{{ __('super.cms.testimonials_desc') }}</p>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-6 col-lg-4">
        <a href="{{ route('super.cms.faqs') }}" class="content-card d-block text-decoration-none cms-card color-inherit">
            <div class="card-body-custom">
                <div class="d-flex align-items-center gap-3">
                    <div class="icon-box icon-box-48 cms-icon-faq">
                        <i class="bi bi-question-circle text-white fs-xl"></i>
                    </div>
                    <div>
                        <h3 class="mb-0 fw-700 fs-base">{{ __('super.cms.faq_title') }}</h3>
                        <p class="mb-0 text-muted small">{{ __('super.cms.faq_desc') }}</p>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-6 col-lg-4">
        <a href="{{ route('super.cms.cta') }}" class="content-card d-block text-decoration-none cms-card color-inherit">
            <div class="card-body-custom">
                <div class="d-flex align-items-center gap-3">
                    <div class="icon-box icon-box-48 cms-icon-cta">
                        <i class="bi bi-megaphone text-white fs-xl"></i>
                    </div>
                    <div>
                        <h3 class="mb-0 fw-700 fs-base">{{ __('super.cms.cta_title') }}</h3>
                        <p class="mb-0 text-muted small">{{ __('super.cms.cta_desc') }}</p>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-6 col-lg-4">
        <a href="{{ route('super.cms.footer') }}" class="content-card d-block text-decoration-none cms-card color-inherit">
            <div class="card-body-custom">
                <div class="d-flex align-items-center gap-3">
                    <div class="icon-box icon-box-48 cms-icon-footer">
                        <i class="bi bi-layout-text-window text-white fs-xl"></i>
                    </div>
                    <div>
                        <h3 class="mb-0 fw-700 fs-base">{{ __('super.cms.footer_title') }}</h3>
                        <p class="mb-0 text-muted small">{{ __('super.cms.footer_desc') }}</p>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-6 col-lg-4">
        <a href="{{ route('super.cms.seo') }}" class="content-card d-block text-decoration-none cms-card color-inherit">
            <div class="card-body-custom">
                <div class="d-flex align-items-center gap-3">
                    <div class="icon-box icon-box-48 cms-icon-seo">
                        <i class="bi bi-search text-white fs-xl"></i>
                    </div>
                    <div>
                        <h3 class="mb-0 fw-700 fs-base">{{ __('super.cms.seo_title') }}</h3>
                        <p class="mb-0 text-muted small">{{ __('super.cms.seo_desc') }}</p>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-6 col-lg-4">
        <a href="{{ route('super.cms.translations') }}" class="content-card d-block text-decoration-none cms-card color-inherit">
            <div class="card-body-custom">
                <div class="d-flex align-items-center gap-3">
                    <div class="icon-box icon-box-48 cms-icon-translate">
                        <i class="bi bi-translate text-white fs-xl"></i>
                    </div>
                    <div>
                        <h3 class="mb-0 fw-700 fs-base">{{ __('super.cms.translations_title') }}</h3>
                        <p class="mb-0 text-muted small">{{ __('super.cms.translations_desc') }}</p>
                    </div>
                </div>
            </div>
        </a>
    </div>
</div>
@endsection
