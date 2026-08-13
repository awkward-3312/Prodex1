@extends('central.super.layout')

@section('title', __('super.subscriptions.title'))

@section('content')
<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
        <h1>{{ __('super.subscriptions.title') }}</h1>
        <p class="page-subtitle">{{ __('super.subscriptions.subtitle') }}</p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-outline btn-sm" id="reloadPageBtn">
            <i class="bi bi-arrow-clockwise"></i> {{ __('super.common.refresh') }}
        </button>
    </div>
</div>

{{-- Summary cards --}}
<div class="row g-3 mb-4">
    @php
        $statuses = ['active', 'trial', 'pending', 'suspended', 'cancelled'];
        $statusIcons = ['active' => 'check-circle-fill', 'trial' => 'hourglass-split', 'pending' => 'clock-fill', 'suspended' => 'pause-circle-fill', 'cancelled' => 'x-circle-fill'];
        $statusIconBg = ['active' => 'stat-icon-success', 'trial' => 'stat-icon-info', 'pending' => 'stat-icon-warning', 'suspended' => 'stat-icon-warning', 'cancelled' => ''];
    @endphp
    @foreach($statuses as $st)
    @php $count = $subscriptions->where('status', $st)->count(); @endphp
    <div class="col-6 col-lg-3">
        <div class="content-card kb-stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon icon-box-md2 fs-base {{ $statusIconBg[$st] ?? '' }}" style="{{ $st === 'cancelled' ? 'background: linear-gradient(135deg, #ef4444, #dc2626);' : '' }}">
                    <i class="bi bi-{{ $statusIcons[$st] }}"></i>
                </div>
                <div>
                    <p class="mb-0 stat-val-2xl">{{ $count }}</p>
                    <p class="mb-0 text-muted text-uppercase-label-sm">{{ __('super.status.' . $st) }}</p>
                </div>
            </div>
        </div>
    </div>
    @endforeach
</div>

<div class="content-card">
    <div class="filter-bar">
        <form method="GET" action="{{ route('super.subscriptions.index') }}" class="d-flex align-items-center gap-3 flex-wrap w-100" id="filterForm">
            <div class="search-box">
                <i class="bi bi-search"></i>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="{{ __('super.subscriptions.search') }}" autocomplete="off">
            </div>
            <select class="form-control filter-select w-auto" name="status" id="statusFilter">
                <option value="">{{ __('super.subscriptions.all_statuses') }}</option>
                <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>{{ __('super.status.active') }}</option>
                <option value="trial" {{ request('status') === 'trial' ? 'selected' : '' }}>{{ __('super.status.trial') }}</option>
                <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>{{ __('super.status.pending') }}</option>
                <option value="suspended" {{ request('status') === 'suspended' ? 'selected' : '' }}>{{ __('super.status.suspended') }}</option>
                <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>{{ __('super.status.cancelled') }}</option>
            </select>
            <span class="text-muted small ms-auto">{{ $subscriptions->total() }} {{ Str::plural('subscription', $subscriptions->total()) }}</span>
        </form>
    </div>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>{{ __('super.subscriptions.tenant') }}</th>
                    <th>{{ __('super.subscriptions.plan') }}</th>
                    <th>{{ __('super.common.status') }}</th>
                    <th>{{ __('super.subscriptions.trial_ends') }}</th>
                    <th>{{ __('super.subscriptions.ends_at') }}</th>
                    <th>{{ __('super.payments.latest_payment') }}</th>
                    <th class="action-cell"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($subscriptions as $s)
                @php
                    $tenantName = $s->tenant->company_name ?? $s->tenant_id;
                    $tenantEmail = $s->tenant->admin_email ?? null;
                    $colors = ['purple', 'blue', 'green', 'amber', 'rose'];
                    $color = $colors[crc32($s->tenant_id) % count($colors)];
                    $initial = strtoupper(mb_substr($tenantName, 0, 1));
                @endphp
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-3">
                            <div class="tenant-avatar tenant-avatar-{{ $color }} tenant-avatar-sm">{{ $initial }}</div>
                            <div>
                                <span class="fw-600 d-block fs-md">{{ $tenantName }}</span>
                                @if($tenantEmail)
                                    <span class="text-muted fs-xs2">{{ $tenantEmail }}</span>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td>
                        @if($s->plan)
                            <div>
                                <span class="fw-600 domain-name">{{ $s->plan->name }}</span>
                                <span class="text-muted d-block fs-xs3">{{ $currencySymbol }}{{ number_format($s->plan->price, 2) }}/{{ __('super.plans.mo') }} · {{ $currencySymbol }}{{ number_format($s->plan->yearly_price, 2) }}/{{ __('super.plans.yr') }}</span>
                            </div>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td>
                        <span class="badge badge-{{ $s->status }}">{{ __('super.status.' . $s->status) }}</span>
                        @if($pendingUpgradeSubIds->contains($s->id))
                            <span class="badge badge-pending-upgrade mt-1 d-block">
                                <i class="bi bi-arrow-up-circle"></i> {{ __('super.subscriptions.pending_upgrade') }}
                            </span>
                        @endif
                    </td>
                    <td>
                        @if($s->trial_ends_at)
                            <span class="fs-sm3">{{ $s->trial_ends_at->format('M d, Y') }}</span>
                            @if($s->trial_ends_at->isFuture())
                                <span class="d-block text-muted fs-xs2">{{ $s->trial_ends_at->diffForHumans() }}</span>
                            @else
                                <span class="d-block fs-xs2 text-danger-600">{{ __('super.status.expired') }}</span>
                            @endif
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td>
                        @if($s->ends_at)
                            <span class="fs-sm3">{{ $s->ends_at->format('M d, Y') }}</span>
                        @else
                            <span class="text-muted fs-sm2">{{ __('super.status.ongoing') }}</span>
                        @endif
                    </td>
                    <td>
                        @php $latestPayment = $s->payments->first(); @endphp
                        @if($latestPayment)
                            <a href="{{ route('super.payments.show', $latestPayment) }}" class="text-decoration-none">
                                <span class="badge badge-{{ $latestPayment->status_color }}">{{ ucfirst($latestPayment->status) }}</span>
                                <span class="d-block text-muted fs-xs2">{{ $currencySymbol }}{{ number_format($latestPayment->amount, 2) }} &middot; {{ $latestPayment->gateway_label }}</span>
                            </a>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="action-cell">
                        <a href="{{ route('super.subscriptions.show', $s) }}" class="btn btn-sm btn-outline">
                            <i class="bi bi-eye"></i> {{ __('super.common.view') }}
                        </a>
                        <a href="{{ route('super.subscriptions.edit', $s) }}" class="btn btn-sm btn-outline">
                            <i class="bi bi-pencil"></i> {{ __('super.common.edit') }}
                        </a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7">
                        <div class="empty-state">
                            <div class="empty-icon"><i class="bi bi-credit-card"></i></div>
                            <h3>{{ __('super.subscriptions.no_subs') }}</h3>
                            <p>{{ __('super.subscriptions.no_subs_desc') }}</p>
                        </div>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($subscriptions->hasPages())
<div class="mt-4 d-flex justify-content-center">
    {{ $subscriptions->links('vendor.pagination.bootstrap-5') }}
</div>
@endif
@push('scripts')
<script src="{{ asset('assets_super/js/subscriptions-index.js') }}"></script>
@endpush

@endsection
