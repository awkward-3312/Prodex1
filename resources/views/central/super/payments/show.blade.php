@extends('central.super.layout')

@section('title', __('super.payments.show_title'))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.payments.index') }}">{{ __('super.payments.transactions_title') }}</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ $payment->invoice_number }}</span>
</div>

<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
        <h1>{{ __('super.payments.payment') }} {{ $payment->invoice_number }}</h1>
        <p class="page-subtitle">
            @php
                $badgeClass = match($payment->status) {
                    'paid' => 'yes',
                    'failed' => 'no',
                    'refunded' => 'info',
                    default => 'pending',
                };
            @endphp
            <span class="badge badge-{{ $badgeClass }}">{{ ucfirst($payment->status) }}</span>
            &middot; {{ $payment->created_at->format('M d, Y \a\t h:i A') }}
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="{{ route('super.payments.invoice-download', $payment) }}" class="btn btn-secondary"><i class="bi bi-download"></i> {{ __('super.payments.download_pdf') }}</a>
        @if($payment->status === 'pending')
            <form method="POST" action="{{ route('super.payments.mark-paid', $payment) }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-primary" data-swal-confirm="{{ json_encode(['title' => __('super.payments.mark_paid'), 'text' => __('super.payments.confirm_mark_paid'), 'icon' => 'question', 'confirmButtonText' => __('super.payments.mark_paid'), 'confirmButtonColor' => '#10b981']) }}"><i class="bi bi-check-lg"></i> {{ __('super.payments.mark_paid') }}</button>
            </form>
        @endif
    </div>
</div>

<div class="row g-4">
    {{-- Main info --}}
    <div class="col-lg-8">
        <div class="content-card mb-4">
            <div class="card-header-custom">
                <h2><i class="bi bi-receipt me-2 text-muted"></i>{{ __('super.payments.payment_info') }}</h2>
                <span class="badge badge-{{ $badgeClass }}">{{ ucfirst($payment->status) }}</span>
            </div>
            <div class="card-body-custom p-0">
                {{-- Amount highlight --}}
                <div class="pi-amount-section">
                    <div class="pi-amount-row">
                        <div>
                            <div class="pi-amount-label">{{ __('super.common.amount') }}</div>
                            <div class="pi-amount-value">{{ $payment->currency }} {{ $currencySymbol }}{{ number_format($payment->amount, 2) }}</div>
                            @if($payment->tax > 0)
                                <div class="pi-amount-tax">+ {{ $currencySymbol }}{{ number_format($payment->tax, 2) }} {{ __('super.payments.tax') }} = <strong>{{ $currencySymbol }}{{ number_format($payment->total, 2) }}</strong> {{ __('super.payments.total') }}</div>
                            @endif
                            @if($payment->gateway_amount && $payment->gateway_currency && strtoupper($payment->gateway_currency) !== strtoupper($payment->currency ?? ''))
                                <div class="pi-amount-tax">
                                    {{ __('super.payments.charged_at_gateway') }}: <strong>{{ strtoupper($payment->gateway_currency) }} {{ number_format($payment->gateway_amount, 2) }}</strong>
                                    @if($payment->exchange_rate && (float) $payment->exchange_rate != 1.0)
                                        ({{ __('super.payments.exchange_rate') }}: {{ rtrim(rtrim(number_format((float) $payment->exchange_rate, 6), '0'), '.') }})
                                    @endif
                                </div>
                            @endif
                        </div>
                        <div class="text-end">
                            <div class="pi-invoice-number">{{ $payment->invoice_number }}</div>
                            <div class="pi-billing-cycle">{{ ucfirst($payment->billing_cycle) }}</div>
                        </div>
                    </div>
                </div>

                {{-- Details grid --}}
                <div class="pi-detail-grid">
                    <div class="pi-cell">
                        <div class="pi-cell-icon pi-cell-icon-primary"><i class="bi bi-credit-card"></i></div>
                        <div>
                            <div class="pi-cell-label">{{ __('super.payments.gateway') }}</div>
                            <div class="pi-cell-value">{{ $payment->gateway_label }}</div>
                            @if($payment->gateway === 'manual' && isset($payment->metadata['offline_method']))
                                <div class="pi-offline-method">{{ ucfirst(str_replace('_', ' ', $payment->metadata['offline_method'])) }}</div>
                            @endif
                        </div>
                    </div>
                    <div class="pi-cell pi-cell-border-left">
                        <div class="pi-cell-icon pi-cell-icon-success"><i class="bi bi-calendar-check"></i></div>
                        <div>
                            <div class="pi-cell-label">{{ __('super.payments.billing_cycle') }}</div>
                            <div class="pi-cell-value">{{ ucfirst($payment->billing_cycle) }}</div>
                        </div>
                    </div>
                </div>

                @if($payment->gateway_payment_id || $payment->transaction_id)
                <div class="pi-detail-grid">
                    @if($payment->gateway_payment_id)
                    <div class="pi-cell">
                        <div class="pi-cell-icon pi-cell-icon-warning"><i class="bi bi-hash"></i></div>
                        <div>
                            <div class="pi-cell-label">{{ __('super.payments.gateway_payment_id') }}</div>
                            <div class="pi-cell-value"><code class="code-inline">{{ $payment->gateway_payment_id }}</code></div>
                        </div>
                    </div>
                    @endif
                    @if($payment->transaction_id)
                    <div class="pi-cell {{ $payment->gateway_payment_id ? 'pi-cell-border-left' : '' }}">
                        <div class="pi-cell-icon pi-cell-icon-info"><i class="bi bi-upc-scan"></i></div>
                        <div>
                            <div class="pi-cell-label">{{ __('super.payments.transaction_id_label') }}</div>
                            <div class="pi-cell-value"><code class="code-inline">{{ $payment->transaction_id }}</code></div>
                        </div>
                    </div>
                    @endif
                </div>
                @endif

                {{-- Timestamps --}}
                <div class="pi-detail-grid-3">
                    @if($payment->paid_at)
                    <div>
                        <div class="pi-cell-label">{{ __('super.payments.paid_at') }}</div>
                        <div class="pi-timestamp-value">{{ $payment->paid_at->format('M d, Y') }}</div>
                        <div class="pi-timestamp-time">{{ $payment->paid_at->format('h:i A') }}</div>
                    </div>
                    @endif
                    <div>
                        <div class="pi-cell-label">{{ __('super.payments.created') }}</div>
                        <div class="pi-timestamp-value">{{ $payment->created_at->format('M d, Y') }}</div>
                        <div class="pi-timestamp-time">{{ $payment->created_at->format('h:i A') }}</div>
                    </div>
                    <div>
                        <div class="pi-cell-label">{{ __('super.payments.updated') }}</div>
                        <div class="pi-timestamp-value">{{ $payment->updated_at->format('M d, Y') }}</div>
                        <div class="pi-timestamp-time">{{ $payment->updated_at->format('h:i A') }}</div>
                    </div>
                </div>

                @if($payment->notes)
                <div class="pi-notes-section">
                    <div class="pi-cell-label pi-notes-label">{{ __('super.common.notes') }}</div>
                    <div class="pi-notes-text">{{ $payment->notes }}</div>
                </div>
                @endif
            </div>
        </div>

        {{-- Proof of Payment --}}
        @php
            $proofPath = $payment->payment_proof_path
                ?: (in_array($payment->gateway, ['manual', 'offline']) && !empty($payment->metadata['payment_proof']) ? $payment->metadata['payment_proof'] : null);
        @endphp
        @if($proofPath)
        <div class="content-card mb-4">
            <div class="card-header-custom">
                <h2><i class="bi bi-file-earmark-image me-2 text-muted"></i>{{ __('super.payments.proof_of_payment') }}</h2>
                @if(in_array($payment->gateway, ['offline', 'manual']) && $payment->status === 'pending')
                    <span class="badge badge-pending">Awaiting Verification</span>
                @endif
            </div>
            <div class="card-body-custom">
                @php
                    $ext = strtolower(pathinfo($proofPath, PATHINFO_EXTENSION));
                    $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'webp']);
                @endphp
                @if($isImage)
                    <a href="{{ asset($proofPath) }}" target="_blank">
                        <img src="{{ asset($proofPath) }}" alt="Payment Proof" class="proof-image">
                    </a>
                @else
                    <a href="{{ asset($proofPath) }}" target="_blank" class="btn btn-outline btn-sm">
                        <i class="bi bi-file-earmark-pdf"></i> {{ __('super.payments.view_proof_pdf') }}
                    </a>
                @endif

                @if(in_array($payment->gateway, ['offline', 'manual']) && $payment->status === 'pending')
                <div class="d-flex gap-2 mt-3 pt-3 border-top">
                    <form method="POST" action="{{ route('super.payments.mark-paid', $payment) }}" class="flex-fill">
                        @csrf
                        <button type="submit" class="btn btn-primary w-100" data-swal-confirm='{"title": "Approve Payment?", "text": "This will mark the payment as paid and activate the subscription.", "icon": "question", "confirmButtonText": "Approve", "confirmButtonColor": "#10b981"}'>
                            <i class="bi bi-check-circle me-1"></i> Approve Payment
                        </button>
                    </form>
                    <form method="POST" action="{{ route('super.payments.mark-failed', $payment) }}" class="flex-fill">
                        @csrf
                        <button type="submit" class="btn btn-outline-danger w-100" data-swal-confirm='{"title": "Reject Payment?", "text": "This will mark the payment as failed.", "icon": "warning", "confirmButtonText": "Reject", "confirmButtonColor": "#ef4444"}'>
                            <i class="bi bi-x-circle me-1"></i> Reject
                        </button>
                    </form>
                </div>
                @endif
            </div>
        </div>
        @endif

    </div>

    {{-- Sidebar --}}
    <div class="col-lg-4">
        {{-- Tenant info --}}
        <div class="content-card mb-4">
            <div class="card-header-custom">
                <h2><i class="bi bi-building me-2 text-muted"></i>{{ __('super.payments.tenant') }}</h2>
            </div>
            <div class="card-body-custom">
                @if($payment->tenant)
                @php
                    $tenantDomain = $payment->tenant->domains->isNotEmpty() ? $payment->tenant->domains->first()->domain : null;
                    $tenantDisplayName = $payment->tenant->company_name ?? ($tenantDomain ?? $payment->tenant_id);
                    $color = ['purple','blue','teal','green','amber'][crc32($tenantDisplayName) % 5];
                @endphp
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="tenant-avatar tenant-avatar-{{ $color }}">{{ strtoupper(mb_substr($tenantDisplayName, 0, 2)) }}</div>
                    <div>
                        <p class="mb-0 fw-700">{{ $tenantDisplayName }}</p>
                        @if($tenantDomain)
                            <p class="mb-0 text-muted fs-xs2">{{ $tenantDomain }}</p>
                        @endif
                        @if($payment->tenant->admin_email)
                            <p class="mb-0 text-muted fs-xs2"><i class="bi bi-envelope"></i> {{ $payment->tenant->admin_email }}</p>
                        @endif
                    </div>
                </div>
                <a href="{{ route('super.tenants.show', $payment->tenant) }}" class="btn btn-outline btn-sm w-100"><i class="bi bi-arrow-right"></i> {{ __('super.payments.view_tenant') }}</a>
                @else
                <p class="text-muted mb-0">{{ __('super.payments.tenant_not_found') }}</p>
                @endif
            </div>
        </div>

        {{-- Subscription info --}}
        @if($payment->subscription)
        <div class="content-card mb-4">
            <div class="card-header-custom">
                <h2><i class="bi bi-credit-card me-2 text-muted"></i>{{ __('super.subscriptions.subscription') }}</h2>
                @php
                    $subBadgeClass = match($payment->subscription->status) {
                        'active' => 'yes',
                        'trial' => 'info',
                        'pending' => 'pending',
                        'cancelled' => 'no',
                        'suspended' => 'pending',
                        'expired' => 'no',
                        default => 'secondary',
                    };
                @endphp
                <span class="badge badge-{{ $subBadgeClass }}">{{ ucfirst($payment->subscription->status) }}</span>
            </div>
            <div class="card-body-custom">
                @if($payment->subscription->plan)
                    <p class="fw-700 mb-1">{{ $payment->subscription->plan->name }}</p>
                @endif
                <p class="text-muted mb-1 fs-sm3">{{ ucfirst($payment->subscription->billing_cycle) }} billing</p>
                @if($payment->subscription->ends_at)
                    <p class="text-muted mb-2 fs-sm3">Ends {{ $payment->subscription->ends_at->format('M d, Y') }}</p>
                @endif
                <a href="{{ route('super.subscriptions.show', $payment->subscription) }}" class="btn btn-outline btn-sm w-100">
                    <i class="bi bi-eye"></i> {{ __('super.common.view') }} {{ __('super.subscriptions.subscription') }}
                </a>
            </div>
        </div>
        @endif

        {{-- Plan info --}}
        <div class="content-card mb-4">
            <div class="card-header-custom">
                <h2><i class="bi bi-tag me-2 text-muted"></i>{{ __('super.payments.plan') }}</h2>
            </div>
            <div class="card-body-custom">
                @if($payment->plan)
                <p class="fw-700 mb-1">{{ $payment->plan->name }}</p>
                <p class="text-muted mb-0 fs-sm3">{{ $currencySymbol }}{{ number_format($payment->plan->price, 2) }}/{{ __('super.plans.mo') }} · {{ $currencySymbol }}{{ number_format($payment->plan->yearly_price, 2) }}/{{ __('super.plans.yr') }}</p>
                @else
                <p class="text-muted mb-0">{{ __('super.payments.no_plan_linked') }}</p>
                @endif
            </div>
        </div>

        {{-- Actions --}}
        <div class="content-card">
            <div class="card-header-custom">
                <h2><i class="bi bi-lightning me-2 text-muted"></i>{{ __('super.common.actions') }}</h2>
            </div>
            <div class="card-body-custom d-flex flex-column gap-2">
                @if($payment->status === 'pending')
                <form method="POST" action="{{ route('super.payments.mark-paid', $payment) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline btn-sm w-100 btn-action-success" data-swal-confirm="{{ json_encode(['title' => __('super.payments.mark_as_paid'), 'text' => __('super.payments.confirm_mark_paid'), 'icon' => 'question', 'confirmButtonText' => __('super.payments.mark_as_paid'), 'confirmButtonColor' => '#10b981']) }}">
                        <i class="bi bi-check-circle"></i> {{ __('super.payments.mark_as_paid') }}
                    </button>
                </form>
                @endif

                @if($payment->status === 'pending')
                <form method="POST" action="{{ route('super.payments.mark-failed', $payment) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline btn-sm w-100 btn-action-danger" data-swal-confirm="{{ json_encode(['title' => __('super.payments.mark_as_failed'), 'text' => __('super.payments.confirm_mark_failed'), 'icon' => 'warning', 'confirmButtonText' => __('super.payments.mark_as_failed'), 'confirmButtonColor' => '#ef4444']) }}">
                        <i class="bi bi-x-circle"></i> {{ __('super.payments.mark_as_failed') }}
                    </button>
                </form>
                @endif

                @if($payment->status === 'paid')
                <form method="POST" action="{{ route('super.payments.mark-refunded', $payment) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline btn-sm w-100 btn-action-info" data-swal-confirm="{{ json_encode(['title' => __('super.payments.mark_as_refunded'), 'text' => __('super.payments.confirm_mark_refunded'), 'icon' => 'info', 'confirmButtonText' => __('super.payments.mark_as_refunded'), 'confirmButtonColor' => '#3b82f6']) }}">
                        <i class="bi bi-arrow-counterclockwise"></i> {{ __('super.payments.mark_as_refunded') }}
                    </button>
                </form>
                @endif

                <a href="{{ route('super.payments.invoice-download', $payment) }}" class="btn btn-outline btn-sm w-100">
                    <i class="bi bi-download"></i> {{ __('super.payments.download_invoice_pdf') }}
                </a>
            </div>
        </div>
    </div>
</div>
@push('scripts')
<script src="{{ asset('assets_super/js/swal-confirm-btn.js') }}"></script>
@endpush

@endsection
