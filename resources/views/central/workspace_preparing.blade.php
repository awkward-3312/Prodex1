<!DOCTYPE html>
@php $appName = \App\Models\Central\GeneralSetting::instance()->app_name ?: 'Stocky'; @endphp
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('central.PreparingYourWorkspace') }} — {{ $appName }}</title>
    <link rel="icon" href="{{ asset('images/super/settings/favicon.ico') }}">
    <link href="{{ asset('assets_super/css/plus-jakarta-sans.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/status-pages.css') }}" rel="stylesheet">
</head>
<body class="page-preparing">

<div class="prep-card">

    {{-- Gradient banner --}}
    <div class="prep-banner">
        <div class="banner-shapes">
            <div class="bshape bshape-1"></div>
            <div class="bshape bshape-2"></div>
        </div>
        <div class="banner-content">
            <a class="brand-logo" href="{{ route('central.welcome') }}">
                <div class="logo-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>
                    </svg>
                </div>
                <span class="logo-text">{{ $appName }}</span>
            </a>
            <h1 id="mainTitle">{{ __('central.SettingUpYourWorkspace') }}</h1>
            <p class="banner-sub" id="mainSubtitle">{{ __('central.SettingUpSubtitle') }}</p>
        </div>
    </div>

    <div class="prep-body">

        {{-- Progress bar --}}
        <div class="progress-track">
            <div class="progress-fill" id="progressBar"></div>
        </div>

        {{-- Status indicator --}}
        <div class="status-box" id="statusBox">
            <div class="loading-spinner" id="spinner"></div>
            <div class="status-icon icon-success" id="successIcon">
                <svg viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
            </div>
            <div class="status-icon icon-fail" id="failIcon">
                <svg viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </div>
            <div class="status-text">
                <h3 id="statusText">
                    @if(($status ?? '') === 'pending' || ($status ?? '') === 'processing')
                        {{ __('central.VerifyingPayment') }}
                    @else
                        {{ __('central.ProvisioningWorkspace') }}
                    @endif
                </h3>
                <p id="statusHint">
                    @if(($status ?? '') === 'pending' || ($status ?? '') === 'processing')
                        {{ __('central.ConfirmingPaymentWithGateway') }}
                    @else
                        {{ __('central.CreatingDatabaseMigrations') }}
                    @endif
                </p>
            </div>
        </div>

        {{-- Step list --}}
        <ul class="steps" id="stepList">
            <li id="step-payment">
                <span class="step-bullet">
                    <svg viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    <span class="dot"></span>
                </span>
                <span>{{ __('central.StepPaymentVerification') }}</span>
            </li>
            <li id="step-tenant">
                <span class="step-bullet">
                    <svg viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    <span class="dot"></span>
                </span>
                <span>{{ __('central.StepCreatingWorkspace') }}</span>
            </li>
            <li id="step-db">
                <span class="step-bullet">
                    <svg viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    <span class="dot"></span>
                </span>
                <span>{{ __('central.StepSettingUpDatabase') }}</span>
            </li>
            <li id="step-ready">
                <span class="step-bullet">
                    <svg viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    <span class="dot"></span>
                </span>
                <span>{{ __('central.StepWorkspaceReady') }}</span>
            </li>
        </ul>

        {{-- Error alert --}}
        <div class="error-alert" id="errorBox"></div>

        {{-- Action buttons (shown on failure) --}}
        <div class="action-buttons" id="actionButtons">
            <button class="btn-retry" id="retryBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="23 4 23 10 17 10"/>
                    <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                </svg>
                {{ __('central.TryAgain') }}
            </button>
            <a href="mailto:support@{{ request()->getHost() }}" class="btn-support">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="4" width="20" height="16" rx="2"/>
                    <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>
                </svg>
                {{ __('central.ContactSupport') }}
            </a>
        </div>

        {{-- Footer --}}
        <div class="prep-footer">
            <span class="workspace-id">{{ __('central.Workspace') }}: <code>{{ $tenantId }}</code></span>
            <span class="elapsed-time" id="elapsedTime">0s</span>
        </div>
    </div>
</div>

<script>
window.WorkspaceData = {
    statusUrl: @json($statusUrl),
    trans: {
        somethingWentWrong:         @json(__('central.SomethingWentWrong')),
        somethingWentWrongSub:      @json(__('central.SomethingWentWrongSub')),
        workspaceReady:             @json(__('central.WorkspaceReady')),
        redirectingNow:             @json(__('central.RedirectingNow')),
        allSet:                     @json(__('central.AllSet')),
        workspaceCreatedSuccess:    @json(__('central.WorkspaceCreatedSuccess')),
        provisioningFailed:         @json(__('central.ProvisioningFailed')),
        couldNotFinishSetup:        @json(__('central.CouldNotFinishSetup')),
        provisioningFailedError:    @json(__('central.ProvisioningFailedError')),
        provisioningFailedSolution: @json(__('central.ProvisioningFailedSolution')),
        timedOutSolution:           @json(__('central.TimedOutSolution')),
        networkErrorSolution:       @json(__('central.NetworkErrorSolution')),
        awaitingAdminApproval:      @json(__('central.AwaitingAdminApproval')),
        registrationBeingReviewed:  @json(__('central.RegistrationBeingReviewed')),
        registrationUnderReview:    @json(__('central.RegistrationUnderReview')),
        paymentReceivedAdminReview: @json(__('central.PaymentReceivedAdminReview')),
        verifyingPayment:           @json(__('central.VerifyingPayment')),
        confirmingPayment:          @json(__('central.ConfirmingPaymentWithGateway')),
        settingUpWorkspace:         @json(__('central.StatusSettingUpWorkspace')),
        workspaceWillBeAt:          @json(__('central.WorkspaceWillBeAt')),
        creatingDatabase:           @json(__('central.CreatingDatabaseMigrations')),
        paymentConfirmed:           @json(__('central.PaymentConfirmedExcl')),
        creatingWorkspaceNow:       @json(__('central.CreatingWorkspaceNow')),
        takingLonger:               @json(__('central.TakingLonger')),
        somethingMayHaveGoneWrong:  @json(__('central.SomethingMayHaveGoneWrong')),
        timedOut:                   @json(__('central.WorkspaceTimedOut')),
        unableToCheck:              @json(__('central.UnableToCheck')),
    },
};
</script>
<script src="{{ asset('assets_super/js/workspace-preparing.js') }}"></script>
</body>
</html>
