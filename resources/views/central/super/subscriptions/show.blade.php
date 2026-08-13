@extends('central.super.layout')

@section('title', __('super.subscriptions.show_title'))

@section('content')
@php
    $tenant = $subscription->tenant;
    $tenantDisplayName = $tenant->company_name ?? ($tenant->domains->first()->domain ?? $tenant->id);
    $tenantEmail = $tenant->admin_email ?? null;
    $tenantDomain = $tenant->domains->first()->domain ?? null;
    $colors = ['purple', 'blue', 'green', 'amber', 'rose'];
    $color = $colors[crc32($subscription->tenant_id) % count($colors)];
    $initial = strtoupper(mb_substr($tenantDisplayName, 0, 2));

    $badgeClass = match($subscription->status) {
        'active' => 'active',
        'trial' => 'trial',
        'pending' => 'pending',
        'cancelled' => 'cancelled',
        'suspended' => 'suspended',
        'expired' => 'expired',
        'failed' => 'failed',
        default => 'secondary',
    };
@endphp

<div class="breadcrumb-custom">
    <a href="{{ route('super.subscriptions.index') }}">{{ __('super.subscriptions.title') }}</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ $tenantDisplayName }}</span>
</div>

<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
        <h1>{{ __('super.subscriptions.subscription') }} — {{ $tenantDisplayName }}</h1>
        <p class="page-subtitle">
            <span class="badge badge-{{ $badgeClass }}">{{ __('super.status.' . $subscription->status) }}</span>
            @if($subscription->plan)
                &middot; {{ $subscription->plan->name }}
            @endif
            @if($subscription->created_at)
                &middot; {{ __('super.subscriptions.show_created') }} {{ $subscription->created_at->format('M d, Y') }}
            @endif
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="{{ route('super.subscriptions.edit', $subscription) }}" class="btn btn-secondary">
            <i class="bi bi-pencil"></i> {{ __('super.common.edit') }}
        </a>
    </div>
</div>

<div class="row g-4">
    {{-- Main info --}}
    <div class="col-lg-8">
        {{-- Subscription details card --}}
        <div class="content-card mb-4">
            <div class="card-header-custom">
                <h2><i class="bi bi-credit-card-2-front me-2 text-muted"></i>{{ __('super.subscriptions.sub_details') }}</h2>
                <span class="badge badge-{{ $badgeClass }}">{{ __('super.status.' . $subscription->status) }}</span>
            </div>
            <div class="card-body-custom p-0">
                {{-- Plan & Amount highlight --}}
                <div class="pi-amount-section">
                    <div class="pi-amount-row">
                        <div>
                            <div class="pi-amount-label">{{ __('super.subscriptions.plan') }}</div>
                            <div class="pi-amount-value">{{ $subscription->plan->name ?? '—' }}</div>
                            @if($subscription->amount)
                                <div class="pi-amount-tax">{{ $subscription->currency ?? $currencyCode }} {{ $currencySymbol }}{{ number_format($subscription->amount, 2) }} / {{ $subscription->billing_cycle }}</div>
                            @endif
                        </div>
                        <div class="text-end">
                            <div class="pi-invoice-number">{{ ucfirst($subscription->billing_cycle ?? '—') }}</div>
                            <div class="pi-billing-cycle">{{ __('super.subscriptions.show_billing_cycle') }}</div>
                        </div>
                    </div>
                </div>

                {{-- Key dates --}}
                <div class="pi-detail-grid">
                    <div class="pi-cell">
                        <div class="pi-cell-icon pi-cell-icon-success"><i class="bi bi-calendar-check"></i></div>
                        <div>
                            <div class="pi-cell-label">{{ __('super.subscriptions.show_starts_at') }}</div>
                            <div class="pi-cell-value">{{ ($subscription->starts_at ?? $subscription->created_at)->format('M d, Y') }}</div>
                        </div>
                    </div>
                    <div class="pi-cell pi-cell-border-left">
                        <div class="pi-cell-icon pi-cell-icon-warning"><i class="bi bi-calendar-x"></i></div>
                        <div>
                            <div class="pi-cell-label">{{ __('super.subscriptions.ends_at') }}</div>
                            <div class="pi-cell-value">
                                @if($subscription->ends_at)
                                    {{ $subscription->ends_at->format('M d, Y') }}
                                    @if($subscription->ends_at->isFuture())
                                        <span class="d-block text-muted fs-xs2">{{ $subscription->ends_at->diffForHumans() }}</span>
                                    @else
                                        <span class="d-block fs-xs2 text-danger-600">{{ __('super.status.expired') }}</span>
                                    @endif
                                @elseif($subscription->status === 'pending')
                                    <span class="text-muted">{{ __('super.subscriptions.awaiting_activation') }}</span>
                                @else
                                    <span class="text-muted">{{ __('super.status.ongoing') }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                @if($subscription->trial_ends_at || $subscription->cancelled_at)
                <div class="pi-detail-grid">
                    @if($subscription->trial_ends_at)
                    <div class="pi-cell">
                        <div class="pi-cell-icon pi-cell-icon-info"><i class="bi bi-hourglass-split"></i></div>
                        <div>
                            <div class="pi-cell-label">{{ __('super.subscriptions.trial_ends_at') }}</div>
                            <div class="pi-cell-value">
                                {{ $subscription->trial_ends_at->format('M d, Y') }}
                                @if($subscription->trial_ends_at->isFuture())
                                    <span class="badge badge-trial ms-1 badge-xs">{{ $subscription->trial_ends_at->diffForHumans() }}</span>
                                @else
                                    <span class="badge badge-expired ms-1 badge-xs">{{ __('super.status.expired') }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                    @endif
                    @if($subscription->cancelled_at)
                    <div class="pi-cell {{ $subscription->trial_ends_at ? 'pi-cell-border-left' : '' }}">
                        <div class="pi-cell-icon pi-cell-icon-primary"><i class="bi bi-x-circle"></i></div>
                        <div>
                            <div class="pi-cell-label">{{ __('super.subscriptions.show_cancelled_at') }}</div>
                            <div class="pi-cell-value">{{ $subscription->cancelled_at->format('M d, Y \a\t h:i A') }}</div>
                        </div>
                    </div>
                    @endif
                </div>
                @endif

                {{-- Timestamps --}}
                <div class="pi-detail-grid-3">
                    <div>
                        <div class="pi-cell-label">{{ __('super.payments.created') }}</div>
                        <div class="pi-timestamp-value">{{ $subscription->created_at->format('M d, Y') }}</div>
                        <div class="pi-timestamp-time">{{ $subscription->created_at->format('h:i A') }}</div>
                    </div>
                    <div>
                        <div class="pi-cell-label">{{ __('super.payments.updated') }}</div>
                        <div class="pi-timestamp-value">{{ $subscription->updated_at->format('M d, Y') }}</div>
                        <div class="pi-timestamp-time">{{ $subscription->updated_at->format('h:i A') }}</div>
                    </div>
                    @if($subscription->ends_at)
                    <div>
                        <div class="pi-cell-label">{{ __('super.subscriptions.show_days_remaining') }}</div>
                        <div class="pi-timestamp-value">{{ $subscription->daysRemaining() }} {{ __('super.subscriptions.show_days') }}</div>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Payment history --}}
        <div class="content-card">
            <div class="card-header-custom">
                <h2><i class="bi bi-receipt me-2 text-muted"></i>{{ __('super.subscriptions.show_payment_history') }}</h2>
                <span class="text-muted fs-sm3">{{ $subscription->payments->count() }} {{ __('super.subscriptions.show_payments_count') }}</span>
            </div>
            <div class="card-body-custom p-0">
                @if($subscription->payments->count())
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>{{ __('super.payments.invoice') }}</th>
                                <th>{{ __('super.common.amount') }}</th>
                                <th>{{ __('super.payments.gateway') }}</th>
                                <th>{{ __('super.common.status') }}</th>
                                <th>{{ __('super.common.date') }}</th>
                                <th class="action-cell"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($subscription->payments as $payment)
                            @php
                                $payBadge = match($payment->status) {
                                    'paid' => 'yes',
                                    'failed' => 'no',
                                    'refunded' => 'info',
                                    default => 'pending',
                                };
                            @endphp
                            <tr>
                                <td><span class="fw-600 fs-sm3">{{ $payment->invoice_number }}</span></td>
                                <td>{{ $currencySymbol }}{{ number_format($payment->amount, 2) }}</td>
                                <td>{{ $payment->gateway_label }}</td>
                                <td><span class="badge badge-{{ $payBadge }}">{{ ucfirst($payment->status) }}</span></td>
                                <td class="fs-sm3">{{ $payment->created_at->format('M d, Y') }}</td>
                                <td class="action-cell">
                                    <a href="{{ route('super.payments.show', $payment) }}" class="btn btn-sm btn-outline">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @else
                <div class="empty-state py-4">
                    <div class="empty-icon"><i class="bi bi-receipt"></i></div>
                    <h3>{{ __('super.subscriptions.show_no_payments') }}</h3>
                    <p>{{ __('super.subscriptions.show_no_payments_desc') }}</p>
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Sidebar --}}
    <div class="col-lg-4">
        {{-- Tenant info --}}
        <div class="content-card mb-4">
            <div class="card-header-custom">
                <h2><i class="bi bi-building me-2 text-muted"></i>{{ __('super.subscriptions.tenant') }}</h2>
            </div>
            <div class="card-body-custom">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="tenant-avatar tenant-avatar-{{ $color }}">{{ $initial }}</div>
                    <div>
                        <p class="mb-0 fw-700">{{ $tenantDisplayName }}</p>
                        @if($tenantDomain)
                            <p class="mb-0 text-muted fs-xs2">{{ $tenantDomain }}</p>
                        @endif
                        @if($tenantEmail)
                            <p class="mb-0 text-muted fs-xs2"><i class="bi bi-envelope"></i> {{ $tenantEmail }}</p>
                        @endif
                    </div>
                </div>
                <a href="{{ route('super.tenants.show', $subscription->tenant_id) }}" class="btn btn-outline btn-sm w-100">
                    <i class="bi bi-arrow-right"></i> {{ __('super.payments.view_tenant') }}
                </a>
            </div>
        </div>

        {{-- Plan info --}}
        @if($subscription->plan)
        <div class="content-card mb-4">
            <div class="card-header-custom">
                <h2><i class="bi bi-tag me-2 text-muted"></i>{{ __('super.subscriptions.plan') }}</h2>
            </div>
            <div class="card-body-custom">
                <p class="fw-700 mb-1">{{ $subscription->plan->name }}</p>
                <p class="text-muted mb-1 fs-sm3">{{ $currencySymbol }}{{ number_format($subscription->plan->price, 2) }}/{{ __('super.plans.mo') }} &middot; {{ $currencySymbol }}{{ number_format($subscription->plan->yearly_price, 2) }}/{{ __('super.plans.yr') }}</p>
                @if($subscription->plan->is_private)
                    <span class="badge badge-secondary">{{ __('super.plans.private') }}</span>
                @endif
            </div>
        </div>
        @endif

        {{-- Allowed transitions --}}
        @php
            $allowedTransitions = \App\Models\Central\TenantSubscription::ALLOWED_TRANSITIONS[$subscription->status] ?? [];
            $hasPaidPayment = $subscription->payments->where('status', 'paid')->isNotEmpty();
            $pendingPayment = $subscription->payments->where('status', 'pending')->first();
            $isPendingNeedsPayment = $subscription->status === 'pending' && !$hasPaidPayment;
        @endphp

        {{-- Actions --}}
        <div class="content-card mb-4">
            <div class="card-header-custom">
                <h2><i class="bi bi-lightning me-2 text-muted"></i>{{ __('super.common.actions') }}</h2>
            </div>
            <div class="card-body-custom d-flex flex-column gap-2">
                @if($isPendingNeedsPayment && $pendingPayment)
                {{-- Pending subscription with unpaid payment — direct admin to approve payment first --}}
                <a href="{{ route('super.payments.show', $pendingPayment) }}" class="btn btn-outline btn-sm w-100 btn-action-success">
                    <i class="bi bi-check-circle"></i> {{ __('super.subscriptions.approve_payment_btn') }}
                </a>
                <div class="d-flex gap-2 align-items-start mt-1 mb-1 action-hint-warning">
                    <i class="bi bi-info-circle flex-shrink-0 mt-1"></i>
                    <span>{{ __('super.subscriptions.payment_required_hint') }}</span>
                </div>
                @elseif($isPendingNeedsPayment && !$pendingPayment)
                {{-- Pending subscription with no payment at all --}}
                <div class="d-flex gap-2 align-items-start action-hint-warning">
                    <i class="bi bi-info-circle flex-shrink-0 mt-1"></i>
                    <span>{{ __('super.subscriptions.no_payment_hint') }}</span>
                </div>
                @elseif(in_array('active', $allowedTransitions))
                <form method="POST" action="{{ route('super.subscriptions.activate', $subscription) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline btn-sm w-100 btn-action-success" data-swal-confirm="{{ json_encode(['title' => __('super.subscriptions.show_activate_title'), 'text' => __('super.subscriptions.show_activate_text'), 'icon' => 'question', 'confirmButtonText' => __('super.subscriptions.show_activate_btn'), 'confirmButtonColor' => '#10b981']) }}">
                        <i class="bi bi-check-circle"></i> {{ __('super.subscriptions.show_activate_btn') }}
                    </button>
                </form>
                @endif

                @if(in_array('suspended', $allowedTransitions))
                <form method="POST" action="{{ route('super.subscriptions.suspend', $subscription) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline btn-sm w-100 btn-action-warning" data-swal-confirm="{{ json_encode(['title' => __('super.subscriptions.show_suspend_title'), 'text' => __('super.subscriptions.show_suspend_text'), 'icon' => 'warning', 'confirmButtonText' => __('super.subscriptions.show_suspend_btn'), 'confirmButtonColor' => '#f59e0b']) }}">
                        <i class="bi bi-pause-circle"></i> {{ __('super.subscriptions.show_suspend_btn') }}
                    </button>
                </form>
                @endif

                @if(in_array('cancelled', $allowedTransitions))
                <form method="POST" action="{{ route('super.subscriptions.cancel', $subscription) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline btn-sm w-100 btn-action-danger" data-swal-confirm="{{ json_encode(['title' => __('super.subscriptions.show_cancel_title'), 'text' => __('super.subscriptions.show_cancel_text'), 'icon' => 'warning', 'confirmButtonText' => __('super.subscriptions.show_cancel_btn'), 'confirmButtonColor' => '#ef4444']) }}">
                        <i class="bi bi-x-circle"></i> {{ __('super.subscriptions.show_cancel_btn') }}
                    </button>
                </form>
                @endif

                <a href="{{ route('super.subscriptions.edit', $subscription) }}" class="btn btn-outline btn-sm w-100">
                    <i class="bi bi-pencil"></i> {{ __('super.subscriptions.edit_sub') }}
                </a>
            </div>
        </div>

        {{-- Notes --}}
        <div class="content-card">
            <div class="card-body-custom">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <i class="bi bi-lightbulb text-muted fs-lg2"></i>
                    <h3 class="mb-0 fw-700 fs-md">{{ __('super.subscriptions.show_status_info') }}</h3>
                </div>
                <div class="d-flex flex-column gap-3 sidebar-tip-list">
                    @if(!empty($allowedTransitions))
                    <div class="d-flex gap-2">
                        <i class="bi bi-dot sidebar-dot-icon"></i>
                        <span>{{ __('super.subscriptions.show_can_transition') }}: <strong>{{ implode(', ', array_map(fn($s) => __('super.status.' . $s), $allowedTransitions)) }}</strong></span>
                    </div>
                    @endif
                    <div class="d-flex gap-2">
                        <i class="bi bi-dot sidebar-dot-icon"></i>
                        <span>{{ __('super.subscriptions.note_plan') }}</span>
                    </div>
                    <div class="d-flex gap-2">
                        <i class="bi bi-dot sidebar-dot-icon"></i>
                        <span>{{ __('super.subscriptions.note_suspend') }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script src="{{ asset('assets_super/js/swal-confirm-btn.js') }}"></script>
@endpush

@endsection
