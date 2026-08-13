@extends('layouts.billing')

@section('title', __('billing.PaymentCancelled'))

@section('content')
<div class="text-center billing-result">
    <div class="mx-auto mb-4 billing-result-icon billing-result-icon-danger">
        <i class="bi bi-x-lg"></i>
    </div>

    <h1 class="billing-result-title">{{ __('billing.PaymentCancelled') }}</h1>
    <p class="text-muted billing-result-subtitle">
        {{ __('billing.PaymentNotCompleted') }}
    </p>

    @if($payment)
    <div class="billing-card text-start mb-4">
        <div class="billing-card-body">
            <div class="d-flex justify-content-between mb-2 billing-detail-row">
                <span class="text-muted">{{ __('billing.Plan') }}</span>
                <span class="fw-bold">{{ $payment->plan->name ?? 'N/A' }}</span>
            </div>
            <div class="d-flex justify-content-between mb-2 billing-detail-row">
                <span class="text-muted">{{ __('billing.Amount') }}</span>
                <span class="fw-bold">{{ $currencySymbol }}{{ number_format($payment->amount, 2) }} {{ $payment->currency }}</span>
            </div>
            <div class="d-flex justify-content-between mb-2 billing-detail-row">
                <span class="text-muted">{{ __('billing.Gateway') }}</span>
                <span class="fw-bold">{{ $payment->gateway_label }}</span>
            </div>
            <div class="d-flex justify-content-between billing-detail-row">
                <span class="text-muted">{{ __('billing.Status') }}</span>
                @if($payment->isPaid())
                    <span class="badge-status badge-paid"><i class="bi bi-check-circle"></i> {{ __('billing.Paid') }}</span>
                @elseif($payment->status === 'failed')
                    <span class="badge-status badge-failed"><i class="bi bi-x-circle"></i> {{ __('billing.Failed') }}</span>
                @else
                    <span class="badge-status badge-pending"><i class="bi bi-clock"></i> {{ __('billing.Pending') }}</span>
                @endif
            </div>
        </div>
    </div>

    @if($payment->isPaid())
    <div class="alert-billing alert-billing-success mb-4">
        <i class="bi bi-info-circle-fill"></i>
        {{ __('billing.PaymentActuallyConfirmed') }}
    </div>
    <div class="d-flex gap-3 justify-content-center">
        <a href="/" class="btn-billing btn-billing-primary">
            <i class="bi bi-arrow-right"></i> {{ __('billing.GoToDashboard') }}
        </a>
    </div>
    @else
    <div class="d-flex gap-3 justify-content-center flex-wrap">
        <a href="{{ route('billing.checkout', ['plan' => $payment->plan_id, 'cycle' => $payment->billing_cycle]) }}" class="btn-billing btn-billing-primary">
            <i class="bi bi-arrow-clockwise"></i> {{ __('billing.TryAgain') }}
        </a>
        <a href="{{ route('billing.plans') }}" class="btn-billing btn-billing-outline">
            <i class="bi bi-grid"></i> {{ __('billing.ViewPlans') }}
        </a>
    </div>
    @endif
    @else
    <div class="d-flex gap-3 justify-content-center">
        <a href="{{ route('billing.plans') }}" class="btn-billing btn-billing-primary">
            <i class="bi bi-arrow-left"></i> {{ __('billing.BackToPlans') }}
        </a>
    </div>
    @endif

    <div class="mt-4 p-3 billing-help-box">
        <p class="mb-1 fw-bold"><i class="bi bi-question-circle"></i> {{ __('billing.CommonPaymentIssues') }}</p>
        <ul class="mb-0 text-start text-muted">
            <li>{{ __('billing.ReasonCancelledBeforeCompleting') }}</li>
            <li>{{ __('billing.ReasonInsufficientFunds') }}</li>
            <li>{{ __('billing.ReasonCardDeclined') }}</li>
            <li>{{ __('billing.ReasonGatewayIssue') }}</li>
        </ul>
    </div>
</div>
@endsection
