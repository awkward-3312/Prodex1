@extends('layouts.billing')

@section('title', __('billing.ChooseAPlan'))

@section('content')
<div class="text-center mb-5">
    <h1 class="billing-plans-title">{{ __('billing.ChooseYourPlan') }}</h1>
    <p class="text-muted billing-plans-subtitle">
        {{ __('billing.PlanSubtitle') }}
    </p>

    {{-- Billing toggle --}}
    <div class="d-inline-flex align-items-center gap-3 mt-4 p-1 billing-cycle-toggle">
        <button class="btn-cycle active" data-cycle="monthly" type="button">{{ __('billing.Monthly') }}</button>
        <button class="btn-cycle" data-cycle="yearly" type="button">
            {{ __('billing.Yearly') }}
            <span class="billing-save-badge">{{ __('billing.Save') }}</span>
        </button>
    </div>
</div>

@if($subscription && $subscription->isActive())
<div class="alert-billing alert-billing-success mb-4 billing-current-alert">
    <i class="bi bi-check-circle-fill"></i>
    {{ __('billing.YoureOnPlan', ['plan' => $subscription->plan->name ?? 'N/A', 'cycle' => ucfirst($subscription->billing_cycle)]) }}
    @if($subscription->ends_at)
        {{ __('billing.Renews') }} {{ $subscription->ends_at->format('M d, Y') }}.
    @endif
</div>
@endif

<div class="row g-4 justify-content-center">
    @foreach($plans as $plan)
    <div class="col-lg-4 col-md-6">
        <div class="billing-card billing-plan-card h-100 d-flex flex-column position-relative {{ $subscription && $subscription->plan_id === $plan->id && $subscription->isActive() ? 'border-primary' : '' }}">
            @if($subscription && $subscription->plan_id === $plan->id && $subscription->isActive())
            <div class="billing-current-badge">
                <span class="badge-status badge-active"><i class="bi bi-check-circle"></i> {{ __('billing.Current') }}</span>
            </div>
            @endif

            <div class="billing-card-body billing-plan-body flex-grow-1 d-flex flex-column">
                <h3 class="billing-plan-name">{{ $plan->name }}</h3>
                <p class="text-muted mb-3 billing-plan-slug">{{ $plan->slug }}</p>

                {{-- Monthly price --}}
                <div class="plan-price" data-cycle="monthly">
                    <span class="billing-plan-price">{{ $currencySymbol }}{{ number_format($plan->price, 2) }}</span>
                    <span class="text-muted billing-plan-period">/{{ __('billing.Month') }}</span>
                </div>
                {{-- Yearly price --}}
                <div class="plan-price" data-cycle="yearly">
                    <span class="billing-plan-price">{{ $currencySymbol }}{{ number_format($plan->getPriceForCycle('yearly'), 2) }}</span>
                    <span class="text-muted billing-plan-period">/{{ __('billing.Year') }}</span>
                    @if($plan->getYearlySavingsPercent() > 0)
                    <span class="billing-plan-savings">
                        {{ __('billing.SavePercent', ['percent' => $plan->getYearlySavingsPercent()]) }}
                    </span>
                    @endif
                </div>

                <hr class="billing-plan-divider">

                {{-- Limits --}}
                <div class="mb-3">
                    <p class="billing-section-label">{{ __('billing.Limits') }}</p>
                    @foreach($plan->getFormattedLimits() as $key => $limit)
                    <div class="d-flex align-items-center gap-2 mb-1 billing-feature-row">
                        <i class="bi {{ $limit['icon'] }} billing-feature-icon"></i>
                        <span>{{ $limit['display'] }} {{ $limit['label'] }}</span>
                    </div>
                    @endforeach
                </div>

                {{-- Features --}}
                @php $activeFeatures = $plan->getActiveFeatures(); @endphp
                @if(count($activeFeatures) > 0)
                <div class="mb-3">
                    <p class="billing-section-label">{{ __('billing.Features') }}</p>
                    @foreach($activeFeatures as $key => $feature)
                    <div class="d-flex align-items-center gap-2 mb-1 billing-feature-row">
                        <i class="bi bi-check-circle-fill billing-feature-check"></i>
                        <span>{{ $feature['label'] }}</span>
                    </div>
                    @endforeach
                </div>
                @endif

                {{-- All available features not in this plan --}}
                @php
                    $missingFeatures = array_diff_key(\App\Models\Central\Plan::AVAILABLE_FEATURES, $activeFeatures);
                @endphp
                @if(count($missingFeatures) > 0)
                <div class="mb-4">
                    @foreach($missingFeatures as $key => $feature)
                    <div class="d-flex align-items-center gap-2 mb-1 billing-feature-disabled">
                        <i class="bi bi-x-circle"></i>
                        <span>{{ $feature['label'] }}</span>
                    </div>
                    @endforeach
                </div>
                @endif

                <div class="mt-auto">
                    @if($subscription && $subscription->plan_id === $plan->id && $subscription->isActive())
                        <span class="btn-billing btn-billing-outline btn-billing-disabled w-100 justify-content-center">
                            <i class="bi bi-check-lg"></i> {{ __('billing.CurrentPlan') }}
                        </span>
                    @else
                        <a href="{{ route('billing.checkout', ['plan' => $plan->id, 'cycle' => 'monthly']) }}"
                           class="btn-billing btn-billing-primary w-100 justify-content-center plan-select-btn"
                           data-plan="{{ $plan->id }}">
                            @if($subscription && $subscription->isActive() && $plan->price > $subscription->plan->price)
                                <i class="bi bi-arrow-up-circle"></i> {{ __('billing.Upgrade') }}
                            @elseif($subscription && $subscription->isActive())
                                <i class="bi bi-arrow-down-circle"></i> {{ __('billing.Switch') }}
                            @else
                                <i class="bi bi-arrow-right"></i> {{ __('billing.GetStarted') }}
                            @endif
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
    @endforeach
</div>

@push('scripts')
<script src="{{ global_asset('assets_super/js/plans.js') }}"></script>
@endpush
@endsection
