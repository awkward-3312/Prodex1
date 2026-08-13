@extends('central.super.layout')

@section('title', __('super.logs_health.tenant_history'))

@push('styles')
<link href="{{ asset('assets_super/css/logs-health.css') }}?v={{ time() }}" rel="stylesheet">
@endpush

@section('content')
@php
    $tenantName = $tenant->company_name ?? $tenant->id;
@endphp

<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <a href="{{ route('super.logs-health.index') }}" class="btn btn-outline btn-sm">
                <i class="bi bi-arrow-left"></i> {{ __('super.logs_health.back') }}
            </a>
        </div>
        <h1>{{ __('super.logs_health.tenant_history') }}</h1>
        <p class="page-subtitle">{{ __('super.logs_health.tenant_history_subtitle', ['tenant' => $tenantName]) }}</p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('super.tenants.show', $tenant->id) }}" class="btn btn-outline btn-sm">
            <i class="bi bi-building"></i> {{ __('super.logs_health.view_tenant') }}
        </a>
    </div>
</div>

<div class="logs-stats-grid logs-stats-grid-3">
    <div class="logs-stat-card">
        <div class="logs-stat-icon logs-stat-icon-total"><i class="bi bi-journal-text"></i></div>
        <div>
            <p class="logs-stat-value">{{ number_format($stats['total']) }}</p>
            <p class="logs-stat-label">{{ __('super.logs_health.total_logs') }}</p>
        </div>
    </div>
    <div class="logs-stat-card">
        <div class="logs-stat-icon logs-stat-icon-unresolved"><i class="bi bi-hourglass-split"></i></div>
        <div>
            <p class="logs-stat-value">{{ number_format($stats['unresolved']) }}</p>
            <p class="logs-stat-label">{{ __('super.logs_health.unresolved') }}</p>
        </div>
    </div>
    <div class="logs-stat-card">
        <div class="logs-stat-icon logs-stat-icon-critical"><i class="bi bi-exclamation-octagon"></i></div>
        <div>
            <p class="logs-stat-value">{{ number_format($stats['critical']) }}</p>
            <p class="logs-stat-label">{{ __('super.logs_health.critical') }}</p>
        </div>
    </div>
</div>

<div class="content-card">
    <div class="table-responsive">
        <table class="logs-table">
            <thead>
                <tr>
                    <th class="action-cell action-cell-start"></th>
                    <th>{{ __('super.logs_health.col_severity') }}</th>
                    <th>{{ __('super.logs_health.col_type') }}</th>
                    <th>{{ __('super.logs_health.col_message') }}</th>
                    <th>{{ __('super.logs_health.col_occurred_at') }}</th>
                    <th>{{ __('super.logs_health.col_status') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs as $log)
                    <tr class="log-row log-row-{{ $log->severity }}">
                        <td class="action-cell action-cell-start">
                            <a class="btn-icon" href="{{ route('super.logs-health.show', $log) }}" title="{{ __('super.logs_health.view_details') }}">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                        <td>
                            <span class="severity-dot severity-dot-{{ $log->severity }}"></span>
                            <span class="small text-muted">{{ __('super.logs_health.severity_' . $log->severity) }}</span>
                        </td>
                        <td>
                            <span class="log-type-badge log-type-badge-{{ $log->type }}">
                                {{ __('super.logs_health.type_' . $log->type) }}
                            </span>
                        </td>
                        <td class="log-message-cell">
                            <span class="log-message-text">{{ \Illuminate\Support\Str::limit($log->message, 120) }}</span>
                        </td>
                        <td>
                            <span title="{{ $log->occurred_at?->toDateTimeString() }}">{{ $log->occurred_at?->diffForHumans() }}</span>
                        </td>
                        <td>
                            @if($log->isResolved())
                                <span class="badge-status badge-status-resolved"><i class="bi bi-check-circle-fill"></i> {{ __('super.logs_health.resolved') }}</span>
                            @else
                                <span class="badge-status badge-status-unresolved"><i class="bi bi-circle-fill"></i> {{ __('super.logs_health.unresolved') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-5">
                            <i class="bi bi-journal-check fs-1 d-block mb-2 text-success"></i>
                            <p class="mb-0">{{ __('super.logs_health.empty_title') }}</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($logs->hasPages())
        <div class="p-3">{{ $logs->links('vendor.pagination.bootstrap-5') }}</div>
    @endif
</div>
@endsection

@push('scripts')
<script src="{{ asset('assets_super/js/logs-health.js') }}?v={{ time() }}"></script>
@endpush
