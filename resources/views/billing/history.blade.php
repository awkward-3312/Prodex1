@extends('layouts.billing')

@section('title', __('billing.BillingHistory'))

@section('content')
<h1 class="billing-page-title">{{ __('billing.BillingAndInvoices') }}</h1>
<p class="text-muted mb-4">{{ __('billing.ViewSubscriptionAndHistory') }}</p>

{{-- Current subscription --}}
@if($subscription)
<div class="billing-card mb-4">
    <div class="billing-card-body">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="billing-sub-icon">
                    <i class="bi bi-box-seam"></i>
                </div>
                <div>
                    <h3 class="mb-0 fw-bold billing-sub-name">{{ $subscription->plan->name ?? 'N/A' }}</h3>
                    <p class="mb-0 text-muted billing-sub-detail">
                        {{ ucfirst($subscription->billing_cycle) }} &middot; {{ $currencySymbol }}{{ number_format($subscription->amount, 2) }}/{{ $subscription->billing_cycle === 'yearly' ? __('billing.Year') : __('billing.Month') }}
                    </p>
                </div>
            </div>
            <div class="d-flex align-items-center gap-3">
                @php
                    $statusClass = match($subscription->status) {
                        'active' => 'badge-active',
                        'pending' => 'badge-pending',
                        'cancelled' => 'badge-cancelled',
                        'expired', 'failed' => 'badge-expired',
                        default => 'badge-pending',
                    };
                @endphp
                <span class="badge-status {{ $statusClass }}">{{ ucfirst($subscription->status) }}</span>

                @if($subscription->isActive() && $subscription->ends_at)
                <span class="text-muted billing-renew-info">
                    @if($subscription->daysRemaining() <= 7)
                        <i class="bi bi-exclamation-triangle text-warning"></i>
                    @endif
                    {{ __('billing.Renews') }} {{ $subscription->ends_at->format('M d, Y') }}
                    ({{ $subscription->daysRemaining() }} {{ __('billing.Days') }})
                </span>
                @endif

                <a href="{{ route('billing.plans') }}" class="btn-billing btn-billing-outline billing-change-btn">
                    @if($subscription->isActive())
                        <i class="bi bi-arrow-repeat"></i> {{ __('billing.ChangePlan') }}
                    @else
                        <i class="bi bi-arrow-right"></i> {{ __('billing.Subscribe') }}
                    @endif
                </a>
            </div>
        </div>
    </div>
</div>
@else
<div class="billing-card mb-4">
    <div class="billing-card-body text-center py-4">
        <i class="bi bi-credit-card billing-empty-icon"></i>
        <p class="text-muted mt-2 mb-3">{{ __('billing.NoActiveSubscription') }}</p>
        <a href="{{ route('billing.plans') }}" class="btn-billing btn-billing-primary">
            <i class="bi bi-arrow-right"></i> {{ __('billing.ChooseAPlan') }}
        </a>
    </div>
</div>
@endif

{{-- Payment history --}}
<div class="billing-card">
    <div class="billing-card-header d-flex align-items-center justify-content-between">
        <span><i class="bi bi-clock-history me-2 text-muted"></i>{{ __('billing.PaymentHistory') }}</span>
        <span class="text-muted billing-history-count">{{ $payments->total() }} {{ __('billing.Transactions') }}</span>
    </div>

    @if($payments->count() > 0)
    <div class="table-responsive">
        <table class="table table-hover mb-0 billing-table">
            <thead>
                <tr>
                    <th>{{ __('billing.Invoice') }}</th>
                    <th>{{ __('billing.Plan') }}</th>
                    <th>{{ __('billing.Amount') }}</th>
                    <th>{{ __('billing.Gateway') }}</th>
                    <th>{{ __('billing.Status') }}</th>
                    <th>{{ __('billing.Date') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($payments as $payment)
                <tr>
                    <td>
                        <span class="fw-bold">{{ $payment->invoice_number ?? '—' }}</span>
                    </td>
                    <td>
                        {{ $payment->plan->name ?? 'N/A' }}
                        <span class="text-muted">({{ ucfirst($payment->billing_cycle) }})</span>
                    </td>
                    <td>
                        <span class="fw-bold">{{ $currencySymbol }}{{ number_format($payment->amount, 2) }}</span>
                        <span class="text-muted">{{ $payment->currency }}</span>
                    </td>
                    <td>
                        {{ $payment->gateway_label }}
                    </td>
                    <td>
                        @php
                            $badgeClass = match($payment->status) {
                                'paid' => 'badge-paid',
                                'pending' => 'badge-pending',
                                'failed' => 'badge-failed',
                                'refunded' => 'badge-refunded',
                                default => 'badge-pending',
                            };
                        @endphp
                        <span class="badge-status {{ $badgeClass }}">{{ ucfirst($payment->status) }}</span>
                    </td>
                    <td>
                        {{ $payment->created_at->format('M d, Y') }}
                    </td>
                    <td>
                        @if($payment->status === 'failed')
                        <form method="POST" action="{{ route('billing.retry', $payment->id) }}" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-primary billing-retry-btn">
                                <i class="bi bi-arrow-clockwise"></i> {{ __('billing.Retry') }}
                            </button>
                        </form>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if($payments->hasPages())
    <div class="billing-card-body pt-0">
        {{ $payments->links() }}
    </div>
    @endif
    @else
    <div class="billing-card-body text-center py-5">
        <i class="bi bi-inbox billing-empty-icon-light"></i>
        <p class="text-muted mt-2 mb-0">{{ __('billing.NoPaymentRecords') }}</p>
    </div>
    @endif
</div>
@endsection
