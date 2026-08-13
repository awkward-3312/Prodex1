@extends('layouts.billing')

@section('title', __('billing.PaymentVerification'))

@section('content')
<div class="text-center billing-result">

    {{-- Verifying state: shown until webhook confirms --}}
    <div id="verifyingState" class="{{ $confirmed ? 'd-none' : '' }}">
        <div class="mx-auto mb-4 billing-result-icon billing-result-icon-warning">
            <div class="spinner-border text-warning billing-result-spinner" role="status">
                <span class="visually-hidden">{{ __('billing.Verifying') }}</span>
            </div>
        </div>
        <h1 class="billing-result-title">{{ __('billing.VerifyingPayment') }}</h1>
        <p class="text-muted billing-result-subtitle">
            {{ __('billing.VerifyingPaymentDescription') }}
        </p>
    </div>

    {{-- Confirmed state: shown after webhook marks payment as paid --}}
    <div id="confirmedState" class="{{ $confirmed ? '' : 'd-none' }}">
        <div class="mx-auto mb-4 billing-result-icon billing-result-icon-success">
            <i class="bi bi-check-lg"></i>
        </div>
        <h1 class="billing-result-title">{{ __('billing.PaymentConfirmed') }}</h1>
        <p class="text-muted billing-result-subtitle">
            {{ __('billing.PaymentConfirmedDescription') }}
        </p>
    </div>

    {{-- Failed state: shown if webhook marks payment as failed --}}
    <div id="failedState" class="d-none">
        <div class="mx-auto mb-4 billing-result-icon billing-result-icon-danger">
            <i class="bi bi-x-lg"></i>
        </div>
        <h1 class="billing-result-title">{{ __('billing.PaymentFailed') }}</h1>
        <p class="text-muted billing-result-subtitle">
            {{ __('billing.PaymentFailedDescription') }}
        </p>
    </div>

    <div id="paymentDetails" class="{{ $confirmed ? '' : 'd-none' }}">
        <div class="billing-card text-start mb-4">
            <div class="billing-card-body">
                <div class="d-flex justify-content-between mb-2 billing-detail-row">
                    <span class="text-muted">{{ __('billing.Invoice') }}</span>
                    <span class="fw-bold" id="detailInvoice">{{ $payment->invoice_number }}</span>
                </div>
                <div class="d-flex justify-content-between mb-2 billing-detail-row">
                    <span class="text-muted">{{ __('billing.Plan') }}</span>
                    <span class="fw-bold" id="detailPlan">{{ $payment->plan->name ?? 'N/A' }}</span>
                </div>
                <div class="d-flex justify-content-between mb-2 billing-detail-row">
                    <span class="text-muted">{{ __('billing.BillingCycle') }}</span>
                    <span class="fw-bold" id="detailCycle">{{ ucfirst($payment->billing_cycle) }}</span>
                </div>
                <div class="d-flex justify-content-between mb-2 billing-detail-row">
                    <span class="text-muted">{{ __('billing.Amount') }}</span>
                    <span class="fw-bold" id="detailAmount">{{ \App\Models\Central\GeneralSetting::currencySymbol() }}{{ number_format($payment->amount, 2) }} {{ $payment->currency }}</span>
                </div>
                <div class="d-flex justify-content-between mb-2 billing-detail-row">
                    <span class="text-muted">{{ __('billing.Gateway') }}</span>
                    <span class="fw-bold" id="detailGateway">{{ $payment->gateway_label }}</span>
                </div>
                <div class="d-flex justify-content-between billing-detail-row">
                    <span class="text-muted">{{ __('billing.Status') }}</span>
                    <span class="badge-status badge-paid" id="detailStatus"><i class="bi bi-check-circle"></i> {{ __('billing.Paid') }}</span>
                </div>
                <div id="detailValidUntilRow" class="d-none">
                    <hr class="billing-hr">
                    <div class="d-flex justify-content-between billing-detail-row">
                        <span class="text-muted">{{ __('billing.ValidUntil') }}</span>
                        <span class="fw-bold" id="detailValidUntil"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="confirmedActions" class="{{ $confirmed ? '' : 'd-none' }}">
        <div class="d-flex gap-3 justify-content-center">
            <a href="/" class="btn-billing btn-billing-primary">
                <i class="bi bi-arrow-right"></i> {{ __('billing.GoToDashboard') }}
            </a>
            <a href="{{ route('billing.history') }}" class="btn-billing btn-billing-outline">
                <i class="bi bi-clock-history"></i> {{ __('billing.BillingHistory') }}
            </a>
        </div>
    </div>

    <div id="failedActions" class="d-none">
        <div class="d-flex gap-3 justify-content-center flex-wrap">
            <a href="{{ route('billing.checkout', ['plan' => $payment->plan_id, 'cycle' => $payment->billing_cycle]) }}" class="btn-billing btn-billing-primary">
                <i class="bi bi-arrow-clockwise"></i> {{ __('billing.TryAgain') }}
            </a>
            <a href="{{ route('billing.plans') }}" class="btn-billing btn-billing-outline">
                <i class="bi bi-grid"></i> {{ __('billing.ViewPlans') }}
            </a>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
window.SuccessData = {
    confirmed:      @json($confirmed),
    statusUrl:      @json(route('billing.payment-status', ['payment' => $payment->id])),
    confirmUrl:     @json(route('billing.payment-confirm', ['payment' => $payment->id])),
    currencySymbol: @json(\App\Models\Central\GeneralSetting::currencySymbol()),
};
</script>
<script src="{{ global_asset('assets_super/js/billing-success.js') }}"></script>
@endpush
