@extends('central.super.layout')

@section('title', __('super.invoices.title'))

@section('content')
<div class="page-header">
    <h1>{{ __('super.invoices.title') }}</h1>
    <p class="page-subtitle">{{ __('super.invoices.subtitle') }}</p>
</div>

{{-- Filters --}}
<div class="content-card mb-4">
    <div class="card-body-custom">
        <form method="GET" action="{{ route('super.payments.invoices') }}" class="d-flex gap-2 align-items-center">
            <input type="text" name="search" class="form-control filter-input-flex" placeholder="{{ __('super.invoices.search') }}" value="{{ request('search') }}">
            <select name="status" class="form-control filter-select-auto">
                <option value="">{{ __('super.invoices.all_statuses') }}</option>
                @foreach(\App\Models\Central\TenantBillingPayment::STATUSES as $s)
                    <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-primary btn-sm btn-nowrap"><i class="bi bi-funnel"></i> {{ __('super.common.filter') }}</button>
            <a href="{{ route('super.payments.invoices') }}" class="btn btn-secondary btn-sm btn-nowrap">{{ __('super.common.clear') }}</a>
        </form>
    </div>
</div>

<div class="content-card">
    <div class="card-header-custom">
        <h2><i class="bi bi-file-earmark-text me-2 text-muted"></i>{{ __('super.invoices.invoices_label') }}</h2>
        <span class="text-muted fs-sm2">{{ $invoices->total() }} {{ Str::plural(__('super.invoices.invoice'), $invoices->total()) }}</span>
    </div>
    @if($invoices->isNotEmpty())
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>{{ __('super.invoices.invoice_number') }}</th>
                    <th>{{ __('super.payments.tenant') }}</th>
                    <th>{{ __('super.payments.plan') }}</th>
                    <th>{{ __('super.common.amount') }}</th>
                    <th>{{ __('super.payments.tax') }}</th>
                    <th>{{ __('super.payments.total') }}</th>
                    <th>{{ __('super.payments.gateway') }}</th>
                    <th>{{ __('super.common.status') }}</th>
                    <th>{{ __('super.payments.date') }}</th>
                    <th class="action-cell">{{ __('super.common.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoices as $inv)
                <tr>
                    <td>
                        <a href="{{ route('super.payments.show', $inv) }}" class="fw-600 table-link-primary">
                            {{ $inv->invoice_number }}
                        </a>
                    </td>
                    <td class="fw-600 fs-sm3">{{ $inv->tenant->company_name ?? $inv->tenant_id }}</td>
                    <td class="fs-sm3">{{ $inv->plan->name ?? '—' }}</td>
                    <td class="fw-600 fs-sm3">{{ $currencySymbol }}{{ number_format($inv->amount, 2) }}</td>
                    <td class="fs-sm3">{{ $inv->tax > 0 ? $currencySymbol . number_format($inv->tax, 2) : '—' }}</td>
                    <td class="fw-700 fs-sm3">{{ $currencySymbol }}{{ number_format($inv->total, 2) }}</td>
                    <td class="fs-sm2">{{ $inv->gateway_label }}</td>
                    <td>
                        @php
                            $badgeClass = match($inv->status) {
                                'paid' => 'yes',
                                'failed' => 'no',
                                'refunded' => 'info',
                                default => 'pending',
                            };
                        @endphp
                        <span class="badge badge-{{ $badgeClass }}">{{ ucfirst($inv->status) }}</span>
                    </td>
                    <td class="fs-sm2">{{ $inv->created_at->format('M d, Y') }}</td>
                    <td class="action-cell">
                        <div class="d-flex gap-1 justify-content-end">
                            <a href="{{ route('super.payments.invoice-download', $inv) }}" class="btn btn-outline btn-sm" title="{{ __('super.payments.download_pdf') }}"><i class="bi bi-download"></i></a>
                            <a href="{{ route('super.payments.show', $inv) }}" class="btn btn-outline btn-sm" title="{{ __('super.common.view') }}"><i class="bi bi-eye"></i></a>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="card-body-custom">
        {{ $invoices->links('vendor.pagination.bootstrap-5') }}
    </div>
    @else
    <div class="empty-state">
        <div class="empty-icon"><i class="bi bi-file-earmark-text"></i></div>
        <h3>{{ __('super.invoices.no_invoices') }}</h3>
        <p>{{ __('super.invoices.no_invoices_desc') }}</p>
    </div>
    @endif
</div>
@endsection
