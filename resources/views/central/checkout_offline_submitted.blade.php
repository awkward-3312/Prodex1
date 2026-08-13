<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('central.PaymentProofSubmitted') }}</title>
    <link rel="icon" href="{{ asset('images/super/settings/favicon.ico') }}">
    <link href="{{ asset('assets_super/css/plus-jakarta-sans.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/central-shared.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/checkout-offline-submitted.css') }}" rel="stylesheet">
</head>
<body>
    <div class="submitted-card">
        <div class="submitted-icon">
            <i class="bi bi-check2-circle"></i>
        </div>

        <h1 class="submitted-title">{{ __('central.PaymentProofSubmitted') }}</h1>
        <p class="submitted-desc">
            {{ __('central.ProofReceivedPendingReview') }}
        </p>

        <div class="info-box">
            <div class="info-box-row">
                <span class="info-box-label">{{ __('central.Plan') }}</span>
                <span class="info-box-value">{{ $plan->name }}</span>
            </div>
            <div class="info-box-row">
                <span class="info-box-label">{{ __('central.Reference') }}</span>
                <span class="info-box-value info-box-value-ref">{{ Str::upper(Str::substr($registration->token, 0, 8)) }}</span>
            </div>
            <div class="info-box-row">
                <span class="info-box-label">{{ __('central.Status') }}</span>
                <span class="info-box-value info-box-value-pending"><i class="bi bi-clock me-1"></i>{{ __('central.PendingVerification') }}</span>
            </div>
        </div>

        <div class="steps-list">
            <p class="mb-2 fw-bold steps-list-title">{{ __('central.WhatHappensNext') }}</p>
            <div class="step">
                <span class="step-num">1</span>
                <span>{{ __('central.StepReviewProof') }}</span>
            </div>
            <div class="step">
                <span class="step-num">2</span>
                <span>{{ __('central.StepWorkspaceCreated') }}</span>
            </div>
            <div class="step">
                <span class="step-num">3</span>
                <span>{{ __('central.StepEmailWorkspaceDetails') }}</span>
            </div>
        </div>

        <a href="{{ route('central.welcome') }}" class="btn btn-outline-secondary w-100">
            <i class="bi bi-house me-1"></i> {{ __('central.BackToHome') }}
        </a>
    </div>
</body>
</html>
