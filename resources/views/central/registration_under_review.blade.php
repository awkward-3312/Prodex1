<!DOCTYPE html>
@php $appName = \App\Models\Central\GeneralSetting::instance()->app_name ?: 'Stocky'; @endphp
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('central.RegistrationUnderReview') }} — {{ $appName }}</title>
    <link rel="icon" href="{{ asset('images/super/settings/favicon.ico') }}">
    <link href="{{ asset('assets_super/css/plus-jakarta-sans.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/status-pages.css') }}" rel="stylesheet">
</head>
<body class="page-review">
    <div class="review-container">
        <!-- Status icon -->
        <div class="review-icon">
            <i class="bi bi-hourglass-split"></i>
        </div>

        <h1 class="review-title">{{ __('central.RegistrationUnderReview') }}</h1>
        <p class="review-subtitle">
            {{ __('central.ThankYouForRegistering', ['company' => $companyName]) }}<br>
            @if($isPaid)
                {{ __('central.PaymentReceivedUnderReview') }}
            @else
                {{ __('central.RegistrationUnderReviewDesc') }}
            @endif
        </p>

        <!-- Status banner -->
        <div class="status-banner">
            <div class="status-banner-icon">
                <i class="bi bi-clock-history"></i>
            </div>
            <div class="status-banner-text">
                <h3>{{ __('central.PendingAdminApproval') }}</h3>
                <p>{{ __('central.PendingAdminApprovalDesc') }}</p>
            </div>
        </div>

        <!-- Registration details -->
        <div class="info-card">
            <div class="info-card-header">
                <i class="bi bi-building"></i> {{ __('central.RegistrationDetails') }}
            </div>
            <div class="info-card-body">
                <div class="info-row info-row-first">
                    <span class="info-label">{{ __('central.Company') }}</span>
                    <span class="info-value">{{ $companyName }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">{{ __('central.Subdomain') }}</span>
                    <span class="info-value"><code>{{ $subdomain }}</code></span>
                </div>
                <div class="info-row">
                    <span class="info-label">{{ __('central.Email') }}</span>
                    <span class="info-value">{{ $adminEmail }}</span>
                </div>
                @if($planName)
                <div class="info-row">
                    <span class="info-label">{{ __('central.Plan') }}</span>
                    <span class="info-value">{{ $planName }}</span>
                </div>
                @endif
                <div class="info-row info-row-last">
                    <span class="info-label">{{ __('central.Registered') }}</span>
                    <span class="info-value">{{ $registeredAt }}</span>
                </div>
            </div>
        </div>

        <!-- What happens next timeline -->
        <div class="timeline-card">
            <div class="timeline-header">
                <i class="bi bi-signpost-split"></i> {{ __('central.WhatHappensNext') }}
            </div>
            <ul class="timeline">
                @if($isPaid)
                <li class="timeline-item is-done">
                    <div class="timeline-dot dot-done"><i class="bi bi-check-lg"></i></div>
                    <div class="timeline-content">
                        <h4>{{ __('central.TimelinePaymentReceived') }}</h4>
                        <p>{{ __('central.TimelinePaymentReceivedDesc') }}</p>
                    </div>
                </li>
                @endif
                <li class="timeline-item is-done">
                    <div class="timeline-dot dot-done"><i class="bi bi-check-lg"></i></div>
                    <div class="timeline-content">
                        <h4>{{ __('central.TimelineRegistrationSubmitted') }}</h4>
                        <p>{{ __('central.TimelineRegistrationSubmittedDesc') }}</p>
                    </div>
                </li>
                <li class="timeline-item is-active">
                    <div class="timeline-dot dot-active"><i class="bi bi-person-check"></i></div>
                    <div class="timeline-content">
                        <h4>{{ __('central.TimelineAdminReview') }}</h4>
                        <p>{{ __('central.TimelineAdminReviewDesc') }}</p>
                    </div>
                </li>
                <li class="timeline-item is-pending">
                    <div class="timeline-dot dot-pending"><i class="bi bi-database-gear"></i></div>
                    <div class="timeline-content">
                        <h4>{{ __('central.TimelineDatabaseSetup') }}</h4>
                        <p>{{ __('central.TimelineDatabaseSetupDesc') }}</p>
                    </div>
                </li>
                <li class="timeline-item is-pending">
                    <div class="timeline-dot dot-pending"><i class="bi bi-rocket-takeoff"></i></div>
                    <div class="timeline-content">
                        <h4>{{ __('central.TimelineWorkspaceActivated') }}</h4>
                        <p>{{ __('central.TimelineWorkspaceActivatedDesc') }}</p>
                    </div>
                </li>
            </ul>
        </div>

        <!-- Email notification notice -->
        <div class="email-notice">
            <i class="bi bi-envelope-check"></i>
            <span>
                {{ __('central.ConfirmationSentTo', ['email' => $adminEmail]) }}
                {{ __('central.AnotherEmailOnApproval') }}
            </span>
        </div>

        <!-- Action buttons -->
        <div class="btn-group-review">
            <a href="{{ route('central.welcome') }}" class="btn-review btn-primary-review">
                <i class="bi bi-house"></i> {{ __('central.BackToHome') }}
            </a>
            <a href="mailto:{{ $supportEmail }}" class="btn-review btn-outline-review">
                <i class="bi bi-envelope"></i> {{ __('central.ContactSupport') }}
            </a>
        </div>

        <p class="review-footer">
            <i class="bi bi-shield-lock-fill"></i>
            {{ __('central.YourDataIsSecure') }} &copy; {{ date('Y') }} {{ $appName }}
        </p>
    </div>
</body>
</html>
