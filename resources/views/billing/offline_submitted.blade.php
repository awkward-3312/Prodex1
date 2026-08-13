@extends('layouts.billing')

@section('title', __('billing.PaymentProofSubmitted'))

@section('content')
<div class="text-center billing-result">
    <div class="mx-auto mb-4 billing-result-icon billing-result-icon-success">
        <i class="bi bi-check2-circle"></i>
    </div>

    <h1 class="billing-result-title">{{ __('billing.PaymentProofSubmitted') }}</h1>
    <p class="text-muted billing-result-subtitle">
        {{ __('billing.ProofReceivedPendingReview') }}
    </p>

    <div class="billing-card text-start mb-4">
        <div class="billing-card-body">
            <div class="d-flex justify-content-between mb-2 billing-detail-row">
                <span class="text-muted">{{ __('billing.Plan') }}</span>
                <span class="fw-bold">{{ $plan->name }}</span>
            </div>
            <div class="d-flex justify-content-between mb-2 billing-detail-row">
                <span class="text-muted">{{ __('billing.Invoice') }}</span>
                <span class="fw-bold">{{ $payment->invoice_number }}</span>
            </div>
            <div class="d-flex justify-content-between mb-2 billing-detail-row">
                <span class="text-muted">{{ __('billing.Amount') }}</span>
                <span class="fw-bold">{{ \App\Models\Central\GeneralSetting::currencySymbol() }}{{ number_format($payment->amount, 2) }} {{ $payment->currency }}</span>
            </div>
            <div class="d-flex justify-content-between mb-2 billing-detail-row">
                <span class="text-muted">{{ __('billing.BillingCycle') }}</span>
                <span class="fw-bold">{{ ucfirst($payment->billing_cycle) }}</span>
            </div>
            <div class="d-flex justify-content-between billing-detail-row">
                <span class="text-muted">{{ __('billing.Status') }}</span>
                <span class="fw-bold billing-status-pending"><i class="bi bi-clock me-1"></i>{{ __('billing.PendingVerification') }}</span>
            </div>
        </div>
    </div>

    <div class="billing-card text-start mb-4">
        <div class="billing-card-body">
            <p class="mb-3 fw-bold billing-next-steps-title">{{ __('billing.WhatHappensNext') }}</p>
            <div class="d-flex align-items-start gap-3 mb-3">
                <span class="d-flex align-items-center justify-content-center flex-shrink-0 billing-step-badge">1</span>
                <span class="text-muted billing-step-text">{{ __('billing.StepReviewProof') }}</span>
            </div>
            <div class="d-flex align-items-start gap-3 mb-3">
                <span class="d-flex align-items-center justify-content-center flex-shrink-0 billing-step-badge">2</span>
                <span class="text-muted billing-step-text">{{ __('billing.StepSubscriptionActivated') }}</span>
            </div>
            <div class="d-flex align-items-start gap-3">
                <span class="d-flex align-items-center justify-content-center flex-shrink-0 billing-step-badge">3</span>
                <span class="text-muted billing-step-text">{{ __('billing.StepEmailConfirmation') }}</span>
            </div>
        </div>
    </div>

    <div class="d-flex gap-3 justify-content-center">
        <a href="/" class="btn-billing btn-billing-primary">
            <i class="bi bi-arrow-left"></i> {{ __('billing.BackToDashboard') }}
        </a>
        <a href="{{ route('billing.history') }}" class="btn-billing btn-billing-outline">
            <i class="bi bi-clock-history"></i> {{ __('billing.BillingHistory') }}
        </a>
    </div>
</div>
@endsection
