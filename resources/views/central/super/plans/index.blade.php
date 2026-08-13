@extends('central.super.layout')

@section('title', __('super.plans.title'))

@section('content')
<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
        <h1>{{ __('super.plans.title') }}</h1>
        <p class="page-subtitle">{{ __('super.plans.subtitle') }}</p>
    </div>
    <a href="{{ route('super.plans.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg"></i> {{ __('super.plans.new_plan') }}
    </a>
</div>

{{-- Plan cards grid --}}
<div class="row g-4 mb-4">
    @forelse($plans as $p)
    @php
        $gradients = [
            ['#6366f1', '#8b5cf6'],
            ['#3b82f6', '#06b6d4'],
            ['#10b981', '#059669'],
            ['#f59e0b', '#f97316'],
            ['#ec4899', '#f43f5e'],
        ];
        $gradient = $gradients[$loop->index % count($gradients)];
        $limits = $p->limits ?? [];
        $features = $p->features ?? [];
        $formattedLimits = $p->getFormattedLimits();
        $activeFeatures = $p->getActiveFeatures();
        $configuredCount = $p->getConfiguredLimitsCount();
    @endphp
    <div class="col-sm-6 col-xl-4">
        <div class="content-card h-100 plan-card">
            <div class="card-body-custom">
                {{-- Header --}}
                <div class="d-flex align-items-start justify-content-between mb-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon icon-box icon-box-44" style="background: linear-gradient(135deg, {{ $gradient[0] }}, {{ $gradient[1] }});">
                            <i class="bi bi-tag-fill fs-lg2"></i>
                        </div>
                        <div>
                            <h3 class="mb-0 fw-700 fs-lg">{{ $p->name }}</h3>
                            <span class="text-mono text-muted fs-xs3">{{ $p->slug }}</span>
                        </div>
                    </div>
                    <div class="d-flex gap-1">
                        @if($p->is_private)
                            <span class="badge badge-private"><i class="bi bi-lock-fill me-1"></i>Private</span>
                        @endif
                        <span class="badge badge-{{ $p->is_active ? 'yes' : 'no' }}">{{ $p->is_active ? __('super.common.active') : __('super.common.inactive') }}</span>
                    </div>
                </div>

                {{-- Price --}}
                <div class="d-flex align-items-baseline gap-2 mb-1">
                    <span class="fw-800 fs-3xl plan-price-main">{{ $currencySymbol }}{{ number_format($p->price, 2) }}</span>
                    <span class="text-muted fs-sm3">/ {{ __('super.plans.mo') }}</span>
                </div>
                <div class="d-flex align-items-baseline gap-2 mb-3">
                    <span class="fw-600 fs-md text-muted">{{ $currencySymbol }}{{ number_format($p->yearly_price, 2) }}</span>
                    <span class="text-muted fs-xs3">/ {{ __('super.plans.yr') }}</span>
                    @if($p->price > 0 && $p->yearly_price > 0 && $p->getYearlySavingsPercent() > 0)
                        <span class="badge badge-yes fs-xs3">{{ __('super.plans.save') }} {{ $p->getYearlySavingsPercent() }}%</span>
                    @endif
                </div>

                {{-- Limits summary --}}
                <div class="plan-limits-section">
                    <p class="plan-section-title">
                        <i class="bi bi-speedometer2"></i> {{ __('super.plans.limits') }}
                        @if($configuredCount > 0)
                            <span class="plan-count-badge">{{ $configuredCount }}</span>
                        @endif
                    </p>
                    <div class="plan-limits-list">
                        @foreach($formattedLimits as $key => $lim)
                        <div class="plan-limit-row">
                            <span class="plan-limit-label">
                                <i class="bi {{ $lim['icon'] }}"></i>
                                {{ $lim['label'] }}
                            </span>
                            <span class="plan-limit-value {{ $lim['value'] < 0 ? 'unlimited' : '' }}">
                                @if($lim['value'] < 0)
                                    <i class="bi bi-infinity"></i>
                                @else
                                    {{ $lim['display'] }}
                                @endif
                            </span>
                        </div>
                        @endforeach
                    </div>
                </div>

                {{-- Features summary --}}
                @if(count(\App\Models\Central\Plan::AVAILABLE_FEATURES) > 0)
                <div class="plan-features-section">
                    <p class="plan-section-title">
                        <i class="bi bi-puzzle"></i> {{ __('super.plans.features') }}
                        @if(count($activeFeatures) > 0)
                            <span class="plan-count-badge">{{ count($activeFeatures) }}</span>
                        @endif
                    </p>
                    <div class="d-flex flex-wrap gap-1">
                        @foreach(\App\Models\Central\Plan::AVAILABLE_FEATURES as $fKey => $fMeta)
                        <span class="plan-feature-tag {{ in_array($fKey, $features) ? 'enabled' : 'disabled' }}">
                            <i class="bi {{ $fMeta['icon'] }}"></i>
                            {{ $fMeta['label'] }}
                        </span>
                        @endforeach
                    </div>
                </div>
                @endif

                {{-- Footer --}}
                <div class="plan-card-footer">
                    <span class="text-muted fs-xs3">
                        <i class="bi bi-people me-1"></i>{{ $p->tenant_subscriptions_count }} {{ Str::plural(__('super.common.subscriber'), $p->tenant_subscriptions_count) }}
                    </span>
                    <a href="{{ route('super.plans.edit', $p) }}" class="btn btn-outline btn-sm">
                        <i class="bi bi-pencil"></i> {{ __('super.common.edit') }}
                    </a>
                </div>
            </div>
        </div>
    </div>
    @empty
    <div class="col-12">
        <div class="content-card">
            <div class="empty-state">
                <div class="empty-icon"><i class="bi bi-tag"></i></div>
                <h3>{{ __('super.plans.no_plans') }}</h3>
                <p>{{ __('super.plans.no_plans_desc') }}</p>
                <a href="{{ route('super.plans.create') }}" class="btn btn-primary">
                    <i class="bi bi-plus-lg"></i> {{ __('super.plans.create_first') }}
                </a>
            </div>
        </div>
    </div>
    @endforelse
</div>

@if($plans->hasPages())
<div class="d-flex justify-content-center">
    {{ $plans->links('vendor.pagination.bootstrap-5') }}
</div>
@endif

@endsection
