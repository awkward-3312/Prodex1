@extends('central.super.layout')

@section('title', __('super.payments.transactions_title'))

@section('content')
<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
        <h1>{{ __('super.payments.transactions_title') }}</h1>
        <p class="page-subtitle">{{ __('super.payments.transactions_sub') }}</p>
    </div>
</div>

{{-- Filters --}}
<div class="content-card mb-4">
    <div class="card-body-custom">
        <form method="GET" action="{{ route('super.payments.index') }}">
            <div class="row g-3">
                <div class="col-4">
                    <input type="text" name="search" class="form-control" placeholder="{{ __('super.payments.search') }}" value="{{ request('search') }}">
                </div>
                <div class="col-4">
                    <select name="status" class="form-control">
                        <option value="">{{ __('super.payments.all_statuses') }}</option>
                        @foreach(\App\Models\Central\TenantBillingPayment::STATUSES as $s)
                            <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-4">
                    <select name="gateway" class="form-control">
                        <option value="">{{ __('super.payments.all_gateways') }}</option>
                        @foreach(\App\Models\Central\TenantBillingPayment::GATEWAYS as $key => $label)
                            <option value="{{ $key }}" {{ request('gateway') === $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-4">
                    <select name="plan_id" class="form-control">
                        <option value="">{{ __('super.payments.all_plans') }}</option>
                        @foreach($plans as $plan)
                            <option value="{{ $plan->id }}" {{ request('plan_id') == $plan->id ? 'selected' : '' }}>{{ $plan->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-4">
                    <input type="date" name="date_from" class="form-control" value="{{ request('date_from') }}" placeholder="{{ __('super.common.from') }}">
                </div>
                <div class="col-4">
                    <input type="date" name="date_to" class="form-control" value="{{ request('date_to') }}" placeholder="{{ __('super.common.to') }}">
                </div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i> {{ __('super.common.filter') }}</button>
                <a href="{{ route('super.payments.index') }}" class="btn btn-secondary btn-sm">{{ __('super.common.clear') }}</a>
            </div>
        </form>
    </div>
</div>

{{-- Table --}}
<div class="content-card">
    <div class="card-header-custom">
        <h2><i class="bi bi-receipt me-2 text-muted"></i>{{ __('super.payments.payments') }}</h2>
        <span class="text-muted fs-sm2">{{ $payments->total() }} {{ Str::plural(__('super.common.record'), $payments->total()) }}</span>
    </div>
    @if($payments->isNotEmpty())
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>{{ __('super.payments.invoice') }}</th>
                    <th>{{ __('super.payments.tenant') }}</th>
                    <th>{{ __('super.payments.plan') }}</th>
                    <th>{{ __('super.payments.gateway') }}</th>
                    <th>{{ __('super.common.amount') }}</th>
                    <th>{{ __('super.payments.cycle') }}</th>
                    <th>{{ __('super.common.status') }}</th>
                    <th>{{ __('super.payments.date') }}</th>
                    <th class="action-cell">{{ __('super.common.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($payments as $payment)
                <tr>
                    <td>
                        <a href="{{ route('super.payments.show', $payment) }}" class="fw-600 table-link-primary">
                            {{ $payment->invoice_number ?? '—' }}
                        </a>
                    </td>
                    <td>
                        <span class="fw-600 fs-sm3">{{ $payment->tenant->company_name ?? $payment->tenant_id }}</span>
                    </td>
                    <td class="fs-sm3">{{ $payment->plan->name ?? '—' }}</td>
                    <td class="fs-sm2">
                        {{ $payment->gateway_label }}
                        @if($payment->gateway === 'manual' && isset($payment->metadata['offline_method']))
                            <br><span class="text-muted pi-offline-method">({{ ucfirst(str_replace('_', ' ', $payment->metadata['offline_method'])) }})</span>
                        @endif
                    </td>
                    <td class="fw-700 fs-sm3">
                        {{ $payment->currency }} {{ $currencySymbol }}{{ number_format($payment->amount, 2) }}
                        @if($payment->tax > 0)
                            <span class="text-muted fs-2xs">+{{ $currencySymbol }}{{ number_format($payment->tax, 2) }}</span>
                        @endif
                        @if($payment->gateway_amount && $payment->gateway_currency && strtoupper($payment->gateway_currency) !== strtoupper($payment->currency ?? ''))
                            <span class="text-muted fs-2xs d-block">{{ strtoupper($payment->gateway_currency) }} {{ number_format($payment->gateway_amount, 2) }}</span>
                        @endif
                    </td>
                    <td><span class="text-muted fs-sm2">{{ ucfirst($payment->billing_cycle) }}</span></td>
                    <td>
                        @php
                            $badgeClass = match($payment->status) {
                                'paid' => 'yes',
                                'failed' => 'no',
                                'refunded' => 'info',
                                default => 'pending',
                            };
                        @endphp
                        <span class="badge badge-{{ $badgeClass }}">{{ ucfirst($payment->status) }}</span>
                    </td>
                    <td class="fs-sm2">{{ $payment->created_at->format('M d, Y') }}</td>
                    <td class="action-cell">
                        <div class="d-flex gap-1 justify-content-end">
                            <a href="{{ route('super.payments.show', $payment) }}" class="btn btn-outline btn-sm" title="{{ __('super.common.view') }}"><i class="bi bi-eye"></i></a>
                            <a href="{{ route('super.payments.invoice-download', $payment) }}" class="btn btn-outline btn-sm" title="{{ __('super.payments.download_pdf') }}"><i class="bi bi-download"></i></a>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="card-body-custom">
        {{ $payments->links('vendor.pagination.bootstrap-5') }}
    </div>
    @else
    <div class="empty-state">
        <div class="empty-icon"><i class="bi bi-receipt"></i></div>
        <h3>{{ __('super.payments.no_transactions') }}</h3>
        <p>{{ __('super.payments.no_trans_desc') }}</p>
    </div>
    @endif
</div>
@endsection
