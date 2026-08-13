@extends('central.super.layout')

@section('title', __('central.CreateTenant'))

@section('content')
@php
    $settings = \App\Models\Central\GeneralSetting::instance();
    $baseDomain = preg_replace('/^www\./i', '', parse_url(config('app.url', 'http://localhost'), PHP_URL_HOST) ?? 'localhost');
    $languages = \App\Models\Central\CentralLanguage::active();
    $defaultLocale = \App\Models\Central\CentralLanguage::defaultLocale();
@endphp

<div class="breadcrumb-custom">
    <a href="{{ route('super.tenants.index') }}">{{ __('super.tenants.title') }}</a>
    <span class="separator">/</span>
    <span class="current">{{ __('central.CreateTenant') }}</span>
</div>

<div class="page-header">
    <h1>{{ __('central.CreateTenant') }}</h1>
    <p class="page-subtitle">{{ __('central.CreateTenantSubtitle') }}</p>
</div>

<form method="POST" action="{{ route('super.tenants.store') }}" id="createTenantForm" enctype="multipart/form-data">
    @csrf

    <div class="row g-4">
        <div class="col-lg-8">
            {{-- Tenant Details --}}
            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-building me-2 text-muted"></i>{{ __('central.TenantDetails') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('central.CompanyName') }} <span class="text-danger">*</span></label>
                                <input
                                    type="text"
                                    name="company_name"
                                    class="form-control @error('company_name') is-invalid @enderror"
                                    value="{{ old('company_name') }}"
                                    placeholder="Acme Inc."
                                    required
                                >
                                @error('company_name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('central.SubdomainLabel') }} <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input
                                        type="text"
                                        name="subdomain"
                                        class="form-control input-domain-field @error('subdomain') is-invalid @enderror"
                                        value="{{ old('subdomain') }}"
                                        placeholder="acme"
                                        required
                                        pattern="[a-z0-9][a-z0-9\-]*[a-z0-9]|[a-z0-9]"
                                    >
                                    <span class="input-group-text input-domain-suffix">.{{ $baseDomain }}</span>
                                </div>
                                @error('subdomain')
                                    <div class="text-danger mt-1 fs-sm2">{{ $message }}</div>
                                @enderror
                                <p class="form-hint">{{ __('central.SubdomainHint') }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="divider"></div>

                    <h3 class="fw-700 mb-1 section-heading">{{ __('central.AdminAccount') }}</h3>
                    <p class="text-muted mb-3 section-desc">{{ __('central.AdminAccountDesc') }}</p>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('central.AdminEmail') }} <span class="text-danger">*</span></label>
                                <input
                                    type="email"
                                    name="admin_email"
                                    class="form-control @error('admin_email') is-invalid @enderror"
                                    value="{{ old('admin_email') }}"
                                    placeholder="admin@company.com"
                                    required
                                >
                                @error('admin_email')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('central.AdminPassword') }} <span class="text-danger">*</span></label>
                                <div class="position-relative">
                                    <input
                                        type="password"
                                        name="admin_password"
                                        class="form-control pe-5 @error('admin_password') is-invalid @enderror"
                                        placeholder="{{ __('central.AdminPasswordPlaceholder') }}"
                                        required
                                        minlength="8"
                                        autocomplete="new-password"
                                    >
                                    <button type="button" class="btn-pw-toggle" tabindex="-1"><i class="bi bi-eye"></i></button>
                                </div>
                                @error('admin_password')
                                    <div class="text-danger mt-1 fs-sm2">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('central.OwnerPhone') }}</label>
                                <input
                                    type="tel"
                                    name="owner_phone"
                                    class="form-control @error('owner_phone') is-invalid @enderror"
                                    value="{{ old('owner_phone') }}"
                                    placeholder="+1 555 123 4567"
                                >
                                <small class="text-muted">{{ __('central.OwnerPhoneHint') }}</small>
                                @error('owner_phone')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>

                    @if($languages->count() > 1)
                    <div class="divider"></div>

                    <h3 class="fw-700 mb-1 section-heading">{{ __('central.LanguagePreference') }}</h3>
                    <p class="text-muted mb-3 section-desc">{{ __('central.LanguagePreferenceDesc') }}</p>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('central.Language') }}</label>
                                <select name="locale" class="form-control @error('locale') is-invalid @enderror">
                                    @foreach($languages as $lang)
                                        <option value="{{ $lang->locale }}" {{ old('locale', $defaultLocale) === $lang->locale ? 'selected' : '' }}>
                                            {{ $lang->name }}{{ $lang->is_default ? ' (' . __('central.Default') . ')' : '' }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('locale')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                    @endif

                    <div class="divider"></div>

                    <h3 class="fw-700 mb-1 section-heading">{{ __('central.Subscription') }}</h3>
                    <p class="text-muted mb-3 section-desc">{{ __('central.SubscriptionDesc') }}</p>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('central.Plan') }} <span class="text-danger">*</span></label>
                                <select name="plan_id" id="planSelect" class="form-control @error('plan_id') is-invalid @enderror" required>
                                    <option value="">{{ __('central.SelectPlanPlaceholder') }}</option>
                                    @foreach($plans as $plan)
                                        <option
                                            value="{{ $plan->id }}"
                                            data-price="{{ $plan->price }}"
                                            data-yearly="{{ $plan->yearly_price ?? ($plan->price * 12) }}"
                                            data-free="{{ $plan->isFree() ? '1' : '0' }}"
                                            data-trial="{{ $plan->hasTrial() ? '1' : '0' }}"
                                            data-trial-days="{{ $plan->getTrialDays() }}"
                                            data-private="{{ $plan->is_private ? '1' : '0' }}"
                                            {{ old('plan_id') == $plan->id ? 'selected' : '' }}
                                        >
                                            {{ $plan->name }} {{ $plan->isFree() ? '(' . __('central.Free') . ')' : '— ' . $settings->currency_symbol . number_format($plan->price, 2) . '/mo' }}{{ $plan->is_private ? ' [' . __('central.Private') . ']' : '' }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('plan_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('central.BillingCycle') }} <span class="text-danger">*</span></label>
                                <select name="billing_cycle" id="billingCycle" class="form-control @error('billing_cycle') is-invalid @enderror" required>
                                    <option value="monthly" {{ old('billing_cycle', 'monthly') === 'monthly' ? 'selected' : '' }}>{{ __('central.Monthly') }}</option>
                                    <option value="yearly" {{ old('billing_cycle') === 'yearly' ? 'selected' : '' }}>{{ __('central.Yearly') }}</option>
                                </select>
                                @error('billing_cycle')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <div id="planInfo" class="mt-3">
                        <div class="p-3 rounded-3 alert-success-box">
                            <p class="mb-0 alert-success-text" id="planInfoText"></p>
                        </div>
                    </div>

                    {{-- Payment Method (visible only for paid, non-trial plans) --}}
                    <div id="paymentMethodSection">
                        <div class="divider"></div>

                        <h3 class="fw-700 mb-1 section-heading">{{ __('central.PaymentMethod') }}</h3>
                        <p class="text-muted mb-3 section-desc">{{ __('central.PaymentMethodDesc') }}</p>

                        @if(count($gateways) === 0)
                            <div class="text-center py-4">
                                <i class="bi bi-exclamation-triangle no-gateways-warning-icon"></i>
                                <p class="text-muted mt-2">{!! __('central.NoGatewaysConfigured') !!}</p>
                            </div>
                        @else
                            <div class="d-flex flex-column gap-2">
                                @foreach($gateways as $i => $gw)
                                <label class="gateway-option {{ $loop->first ? 'selected' : '' }}">
                                    <input type="radio" name="gateway" value="{{ $gw['key'] }}" {{ $loop->first ? 'checked' : '' }} class="d-none gateway-radio">
                                    <div class="gateway-card">
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="gateway-icon-box" style="background: {{ $gw['color'] }}15;">
                                                <i class="bi {{ $gw['icon'] }} gateway-icon" style="color: {{ $gw['color'] }};"></i>
                                            </div>
                                            <div>
                                                <p class="mb-0 fw-bold gateway-label">{{ $gw['label'] }}</p>
                                                <p class="mb-0 text-muted gateway-desc">
                                                    @if($gw['key'] === 'offline')
                                                        {{ __('central.GatewayOfflineDesc') }}
                                                    @else
                                                        {{ __('central.GatewayOnlineDesc', ['label' => $gw['label']]) }}
                                                    @endif
                                                </p>
                                            </div>
                                        </div>
                                        <div class="gateway-check">
                                            <i class="bi bi-check-circle-fill"></i>
                                        </div>
                                    </div>
                                </label>
                                @endforeach
                            </div>
                            @error('gateway')
                                <div class="text-danger mt-2 fs-sm2">{{ $message }}</div>
                            @enderror
                            <div class="mt-3 p-3 rounded-3" id="gatewayInfoBox">
                                <p class="mb-0" id="gatewayInfoText">
                                    <i class="bi bi-shield-check me-1"></i>
                                    {{ __('central.GatewayRedirectNotice') }}
                                </p>
                            </div>

                            {{-- Payment Proof Upload (visible only for offline/bank transfer) --}}
                            <div id="paymentProofSection" class="mt-3">
                                <label class="form-label">
                                    <i class="bi bi-upload me-1"></i> {{ __('central.PaymentProof') }}
                                </label>
                                <div class="payment-proof-upload-area" id="proofUploadArea">
                                    <input type="file" name="payment_proof" id="paymentProofInput" accept=".jpg,.jpeg,.png,.webp,.pdf" class="d-none">
                                    <div id="proofPlaceholder">
                                        <i class="bi bi-cloud-arrow-up proof-upload-icon"></i>
                                        <p class="mb-0 mt-1 proof-upload-hint">{{ __('central.ClickOrDragFile') }}</p>
                                        <p class="mb-0 text-muted proof-upload-types">{{ __('central.ProofFileTypes') }}</p>
                                    </div>
                                    <div id="proofPreview">
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="bi bi-file-earmark-check proof-check-icon"></i>
                                            <span id="proofFileName" class="proof-file-name"></span>
                                            <button type="button" id="proofRemoveBtn" class="btn btn-sm btn-link text-danger p-0 ms-auto proof-remove-btn">{{ __('central.Remove') }}</button>
                                        </div>
                                    </div>
                                </div>
                                @error('payment_proof')
                                    <div class="text-danger mt-1 fs-sm2">{{ $message }}</div>
                                @enderror
                                <p class="form-hint mt-1">{{ __('central.ProofHint') }}</p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="d-flex align-items-center gap-3">
                <button type="submit" class="btn btn-primary" id="btnCreateTenant" disabled>
                    <i class="bi bi-plus-lg"></i> {{ __('central.CreateTenant') }}
                </button>
                <a href="{{ route('super.tenants.index') }}" class="back-link">{{ __('central.Cancel') }}</a>
            </div>
        </div>

        {{-- Sidebar --}}
        <div class="col-lg-4">
            <div class="content-card sticky-sidebar">
                <div class="card-header-custom">
                    <h2><i class="bi bi-info-circle me-2 text-muted"></i>{{ __('central.HowItWorks') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="d-flex flex-column gap-3 tips-card-body">
                        <div class="d-flex gap-2">
                            <i class="bi bi-1-circle-fill flex-shrink-0 color-primary"></i>
                            <span>{{ __('central.CreateTenantTip1') }}</span>
                        </div>
                        <div class="d-flex gap-2">
                            <i class="bi bi-2-circle-fill flex-shrink-0 color-primary"></i>
                            <span>{{ __('central.CreateTenantTip2') }}</span>
                        </div>
                        <div class="d-flex gap-2">
                            <i class="bi bi-3-circle-fill flex-shrink-0 color-primary"></i>
                            <span>{!! __('central.CreateTenantTip3') !!}</span>
                        </div>
                        <div class="d-flex gap-2">
                            <i class="bi bi-shield-check flex-shrink-0 color-success"></i>
                            <span>{{ __('central.CreateTenantTip4') }}</span>
                        </div>
                        <div class="d-flex gap-2">
                            <i class="bi bi-lightning-charge flex-shrink-0 color-warning"></i>
                            <span>{{ __('central.CreateTenantTip5') }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>


@push('scripts')
<script>
window.TenantCreateData = {
    hasGateways: {{ count($gateways) > 0 ? 'true' : 'false' }},
    currencySymbol: @json($settings->currency_symbol ?? '$'),
    trans: {
        freePlan: @json(__('central.PlanInfoFree')),
        trialPlan: @json(__('central.PlanInfoTrial')),
        paidPlan: @json(__('central.PlanInfoPaid')),
        privateTag: @json(__('central.Private')),
        monthly: @json(__('central.Monthly')),
        yearly: @json(__('central.Yearly')),
        creatingTenant: @json(__('central.CreatingTenantText')),
        redirectingToPayment: @json(__('central.RedirectingToPayment')),
        createBtn: @json(__('central.CreateTenant')),
        createPayBtn: @json(__('central.CreateAndPay')),
        createBankBtn: @json(__('central.CreateBank')),
        gatewayOfflineInfo: @json(__('central.GatewayOfflinePaidInfo')),
        gatewayOnlineInfo: @json(__('central.GatewayRedirectNotice')),
    },
};
</script>
<script src="{{ asset('assets_super/js/tenants-create.js') }}"></script>
@endpush
@endsection
