@extends('layouts.billing')

@section('title', __('billing.Checkout') . ' — ' . $plan->name)

@push('styles')
<link href="{{ global_asset('assets_super/css/checkout.css') }}" rel="stylesheet">
@endpush

@section('content')
<div class="mb-4">
    <a href="{{ route('billing.plans') }}" class="billing-back-link">
        <i class="bi bi-arrow-left"></i> {{ __('billing.BackToPlans') }}
    </a>
</div>

@if($errors->any())
<div class="alert alert-danger d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-x-circle-fill"></i>
    {{ $errors->first() }}
</div>
@endif

@if(isset($pendingPayment) && $pendingPayment)
<div class="billing-card billing-card-pending mb-4">
    <div class="billing-card-body">
        <div class="d-flex align-items-start gap-3">
            <i class="bi bi-hourglass-split billing-pending-icon"></i>
            <div class="flex-grow-1">
                <h5 class="mb-1 fw-bold">{{ __('billing.PaymentPendingReview') }}</h5>
                <p class="mb-2 text-muted">{{ __('billing.PendingPaymentDescription') }}</p>
                <div class="d-flex flex-wrap gap-3 mb-3 billing-pending-meta">
                    <span><strong>{{ __('billing.Gateway') }}:</strong> {{ $pendingPayment->gateway_label }}</span>
                    <span><strong>{{ __('billing.Amount') }}:</strong> {{ $currencySymbol }}{{ number_format($pendingPayment->amount, 2) }}</span>
                    <span><strong>{{ __('billing.Submitted') }}:</strong> {{ $pendingPayment->created_at->diffForHumans() }}</span>
                </div>
                <div class="d-flex gap-2">
                    <form method="POST" action="{{ route('billing.cancel-payment', $pendingPayment) }}" onsubmit="return confirm('{{ __('billing.ConfirmCancelPayment') }}')">
                        @csrf
                        <button type="submit" class="btn btn-outline-danger btn-sm">
                            <i class="bi bi-x-circle me-1"></i> {{ __('billing.CancelThisPayment') }}
                        </button>
                    </form>
                    <a href="{{ route('billing.plans') }}" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-left me-1"></i> {{ __('billing.BackToPlans') }}
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
@endif

<div class="row g-4" @if(isset($pendingPayment) && $pendingPayment) style="opacity: 0.4; pointer-events: none;" @endif>
    {{-- Left: Order summary --}}
    <div class="col-lg-5 order-lg-2">
        <div class="billing-card billing-order-sticky">
            <div class="billing-card-header">
                <i class="bi bi-receipt me-2 text-muted"></i>{{ __('billing.OrderSummary') }}
            </div>
            <div class="billing-card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">{{ __('billing.Plan') }}</span>
                    <span class="fw-bold">{{ $plan->name }}</span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">{{ __('billing.BillingCycle') }}</span>
                    <span class="fw-bold" id="displayCycle">{{ ucfirst($cycle) }}</span>
                </div>
                <hr class="billing-hr">
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">{{ __('billing.Subtotal') }}</span>
                    <span class="fw-bold" id="displayAmount">{{ $currencySymbol }}{{ number_format($amount, 2) }}</span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">{{ __('billing.Tax') }}</span>
                    <span class="fw-bold">{{ $currencySymbol }}0.00</span>
                </div>
                <hr class="billing-hr">
                <div class="d-flex justify-content-between">
                    <span class="billing-total-label">{{ __('billing.Total') }}</span>
                    <span class="billing-total-amount" id="displayTotal">{{ $currencySymbol }}{{ number_format($amount, 2) }}</span>
                </div>

                @if($subscription && $subscription->isActive() && $subscription->plan_id !== $plan->id)
                <div class="mt-3 p-3 billing-upgrade-notice">
                    <i class="bi bi-info-circle me-1"></i>
                    @if($plan->price > ($subscription->plan->price ?? 0))
                        <strong>{{ __('billing.Upgrade') }}:</strong> {{ __('billing.UpgradeNotice') }}
                    @else
                        <strong>{{ __('billing.Downgrade') }}:</strong> {{ __('billing.DowngradeNotice') }}
                    @endif
                </div>
                @endif

                {{-- Plan features summary --}}
                <div class="mt-3 pt-3 billing-included-section">
                    <p class="billing-included-label">{{ __('billing.Included') }}</p>
                    @foreach($plan->getFormattedLimits() as $limit)
                    <div class="d-flex align-items-center gap-2 mb-1 billing-included-item">
                        <i class="bi bi-check text-success"></i>
                        <span>{{ $limit['display'] }} {{ $limit['label'] }}</span>
                    </div>
                    @endforeach
                    @foreach($plan->getActiveFeatures() as $feature)
                    <div class="d-flex align-items-center gap-2 mb-1 billing-included-item">
                        <i class="bi bi-check text-success"></i>
                        <span>{{ $feature['label'] }}</span>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- Right: Payment form --}}
    <div class="col-lg-7 order-lg-1">
        <form method="POST" action="{{ route('billing.process', $plan->id) }}" id="checkoutForm" enctype="multipart/form-data">
            @csrf

            {{-- Billing cycle selector --}}
            <div class="billing-card mb-4">
                <div class="billing-card-header">
                    <i class="bi bi-calendar3 me-2 text-muted"></i>{{ __('billing.BillingCycle') }}
                </div>
                <div class="billing-card-body">
                    <div class="d-flex gap-3">
                        <label class="cycle-option flex-fill {{ $cycle === 'monthly' ? 'selected' : '' }}">
                            <input type="radio" name="billing_cycle" value="monthly" {{ $cycle === 'monthly' ? 'checked' : '' }} class="d-none cycle-radio">
                            <div class="cycle-card">
                                <div>
                                    <p class="mb-0 fw-bold billing-cycle-name">{{ __('billing.Monthly') }}</p>
                                    <p class="mb-0 text-muted billing-cycle-desc">{{ __('billing.BilledEveryMonth') }}</p>
                                </div>
                                <span class="fw-bold billing-cycle-price">{{ $currencySymbol }}{{ number_format($plan->price, 2) }}</span>
                            </div>
                        </label>
                        <label class="cycle-option flex-fill {{ $cycle === 'yearly' ? 'selected' : '' }}">
                            <input type="radio" name="billing_cycle" value="yearly" {{ $cycle === 'yearly' ? 'checked' : '' }} class="d-none cycle-radio">
                            <div class="cycle-card">
                                <div>
                                    <p class="mb-0 fw-bold billing-cycle-name">
                                        {{ __('billing.Yearly') }}
                                        @if($plan->getYearlySavingsPercent() > 0)
                                        <span class="billing-discount-badge">-{{ $plan->getYearlySavingsPercent() }}%</span>
                                        @endif
                                    </p>
                                    <p class="mb-0 text-muted billing-cycle-desc">{{ __('billing.BilledAnnually') }}</p>
                                </div>
                                <span class="fw-bold billing-cycle-price">{{ $currencySymbol }}{{ number_format($plan->getPriceForCycle('yearly'), 2) }}</span>
                            </div>
                        </label>
                    </div>
                </div>
            </div>

            {{-- Payment gateway selector --}}
            <div class="billing-card mb-4">
                <div class="billing-card-header">
                    <i class="bi bi-credit-card me-2 text-muted"></i>{{ __('billing.PaymentMethod') }}
                </div>
                <div class="billing-card-body">
                    @if(count($gateways) === 0)
                        <div class="text-center py-4">
                            <i class="bi bi-exclamation-triangle billing-no-gateways"></i>
                            <p class="text-muted mt-2">{{ __('billing.NoGatewaysAvailable') }}</p>
                        </div>
                    @else
                        <div class="d-flex flex-column gap-2">
                            @foreach($gateways as $i => $gw)
                            <label class="gateway-option {{ $i === 0 ? 'selected' : '' }}">
                                <input type="radio" name="gateway" value="{{ $gw['key'] }}" {{ $i === 0 ? 'checked' : '' }} class="d-none gateway-radio">
                                <div class="gateway-card">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="billing-gw-icon" style="background: {{ $gw['color'] }}15;">
                                            <i class="bi {{ $gw['icon'] }}" style="color: {{ $gw['color'] }};"></i>
                                        </div>
                                        <div>
                                            <p class="mb-0 fw-bold billing-gw-name">{{ $gw['label'] }}</p>
                                            <p class="mb-0 text-muted billing-gw-desc">
                                                @if($gw['key'] === 'offline')
                                                    {{ __('billing.PayViaBankTransfer') }}
                                                @else
                                                    {{ __('billing.PaySecurelyWith') }} {{ $gw['label'] }}
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
                    @endif
                </div>
            </div>

            {{-- Offline payment section (bank details + proof upload) --}}
            <div class="billing-card mb-4" id="offlineSection">
                <div class="billing-card-header">
                    <i class="bi bi-bank me-2 text-muted"></i>{{ __('billing.BankTransferDetails') }}
                </div>
                <div class="billing-card-body">
                    <div class="offline-amount-box mb-3">
                        <p class="mb-1 text-muted offline-amount-label">{{ __('billing.AmountToTransfer') }}</p>
                        <p class="mb-0 fw-bold offline-amount-value">{{ $currencySymbol }}<span id="offlineAmount">{{ number_format($amount, 2) }}</span> {{ $currencyCode }}</p>
                    </div>

                    @if(!empty($bankDetails) && (($bankDetails['bank_name'] ?? '') || ($bankDetails['account_number'] ?? '')))
                    <div class="bank-details-grid mb-3">
                        @if($bankDetails['bank_name'] ?? '')
                        <div class="bank-detail-row">
                            <span class="bank-detail-label">{{ __('billing.BankName') }}</span>
                            <span class="bank-detail-value">{{ $bankDetails['bank_name'] }}</span>
                        </div>
                        @endif
                        @if($bankDetails['account_holder'] ?? '')
                        <div class="bank-detail-row">
                            <span class="bank-detail-label">{{ __('billing.AccountHolder') }}</span>
                            <span class="bank-detail-value">{{ $bankDetails['account_holder'] }}</span>
                        </div>
                        @endif
                        @if($bankDetails['account_number'] ?? '')
                        <div class="bank-detail-row">
                            <span class="bank-detail-label">{{ __('billing.AccountNumber') }}</span>
                            <span class="bank-detail-value">{{ $bankDetails['account_number'] }}</span>
                        </div>
                        @endif
                        @if($bankDetails['iban'] ?? '')
                        <div class="bank-detail-row">
                            <span class="bank-detail-label">{{ __('billing.IBAN') }}</span>
                            <span class="bank-detail-value">{{ $bankDetails['iban'] }}</span>
                        </div>
                        @endif
                        @if($bankDetails['swift'] ?? '')
                        <div class="bank-detail-row">
                            <span class="bank-detail-label">{{ __('billing.SwiftBic') }}</span>
                            <span class="bank-detail-value">{{ $bankDetails['swift'] }}</span>
                        </div>
                        @endif
                        @if($bankDetails['branch'] ?? '')
                        <div class="bank-detail-row">
                            <span class="bank-detail-label">{{ __('billing.Branch') }}</span>
                            <span class="bank-detail-value">{{ $bankDetails['branch'] }}</span>
                        </div>
                        @endif
                    </div>
                    @if($bankDetails['instructions'] ?? '')
                    <div class="bank-instructions mb-3">
                        <p class="mb-1 fw-600 bank-instructions-label">{{ __('billing.Instructions') }}</p>
                        <p class="mb-0 text-muted bank-instructions-text">{{ $bankDetails['instructions'] }}</p>
                    </div>
                    @endif
                    @else
                    <div class="alert alert-warning d-flex align-items-center gap-2 mb-3 bank-not-configured">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        {{ __('billing.BankDetailsNotConfigured') }}
                    </div>
                    @endif

                    <hr class="billing-hr">

                    <div class="mb-2">
                        <label class="form-label fw-600 upload-label">
                            <i class="bi bi-upload me-1"></i> {{ __('billing.UploadProofOfPayment') }} <span class="text-danger">*</span>
                        </label>
                        <div class="offline-upload-area" id="uploadArea">
                            <input type="file" name="payment_proof" id="paymentProof" accept=".jpg,.jpeg,.png,.webp,.pdf" class="d-none">
                            <div id="uploadPlaceholder">
                                <i class="bi bi-cloud-arrow-up upload-icon-placeholder"></i>
                                <p class="mb-1 fw-600 upload-label">{{ __('billing.ClickOrDragFileHere') }}</p>
                                <p class="mb-0 text-muted upload-hint-text">{{ __('billing.AllowedFileTypes') }}</p>
                            </div>
                            <div id="uploadPreview">
                                <i class="bi bi-file-earmark-check upload-icon-preview"></i>
                                <p class="mb-0 fw-600 upload-file-name" id="uploadFileName"></p>
                                <button type="button" class="btn btn-sm btn-outline-danger mt-1" id="removeFile">
                                    <i class="bi bi-x-lg"></i> {{ __('billing.Remove') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Submit --}}
            @if(count($gateways) > 0)
            <button type="submit" class="btn-billing btn-billing-primary btn-billing-pay w-100 justify-content-center" id="payBtn">
                <i class="bi bi-lock-fill"></i>
                <span id="payBtnText">{{ __('billing.Pay') }} {{ $currencySymbol }}<span id="payAmount">{{ number_format($amount, 2) }}</span> {{ $currencyCode }}</span>
            </button>
            <p class="text-center text-muted mt-3 billing-secure-notice" id="secureNote">
                <i class="bi bi-shield-check"></i>
                {{ __('billing.SecurePaymentNotice') }}
            </p>
            @endif
        </form>
    </div>
</div>

@push('scripts')
<script>
window.CheckoutData = {
    prices: { monthly: {{ $plan->price }}, yearly: {{ $plan->getPriceForCycle('yearly') }} },
    currencySymbol: @json($currencySymbol),
    currencyCode: @json($currencyCode),
    trans: {
        submitPaymentProof: @json(__('billing.SubmitPaymentProof')),
        pay: @json(__('billing.Pay')),
        proofReviewed: @json(__('billing.ProofReviewedNotice')),
        securePayment: @json(__('billing.SecurePaymentNotice')),
        fileTooLarge: @json(__('billing.FileTooLarge')),
        submitting: @json(__('billing.Submitting')),
        redirecting: @json(__('billing.RedirectingToPayment')),
    },
};
</script>
<script src="{{ global_asset('assets_super/js/checkout.js') }}"></script>
@endpush
@endsection
