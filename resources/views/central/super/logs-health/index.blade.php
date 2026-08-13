@extends('central.super.layout')

@section('title', __('super.logs_health.title'))

@push('styles')
<link href="{{ asset('assets_super/css/logs-health.css') }}?v={{ time() }}" rel="stylesheet">
@endpush

@section('content')
<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
        <h1>{{ __('super.logs_health.title') }}</h1>
        <p class="page-subtitle">{{ __('super.logs_health.subtitle') }}</p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-outline btn-sm" id="btnRefreshPage">
            <i class="bi bi-arrow-clockwise"></i> {{ __('super.common.refresh') }}
        </button>
        @if(($stats['resolved'] ?? 0) > 0)
            <button class="btn btn-outline-danger btn-sm" id="btnClearResolved" data-url="{{ route('super.logs-health.clear-resolved') }}">
                <i class="bi bi-trash"></i> {{ __('super.logs_health.clear_resolved') }}
            </button>
        @endif
    </div>
</div>

{{-- Overall health banner --}}
<div class="health-banner health-banner-{{ $healthStatus['level'] }}">
    <div class="health-banner-icon">
        @if($healthStatus['level'] === 'healthy')
            <i class="bi bi-shield-check"></i>
        @elseif($healthStatus['level'] === 'warning')
            <i class="bi bi-exclamation-triangle"></i>
        @else
            <i class="bi bi-exclamation-octagon"></i>
        @endif
    </div>
    <div class="health-banner-text">
        <p class="health-banner-title">{{ __('super.logs_health.health_' . $healthStatus['level']) }}</p>
        <p class="health-banner-subtitle">
            @if($healthStatus['level'] === 'healthy')
                {{ __('super.logs_health.health_msg_healthy') }}
            @elseif($healthStatus['level'] === 'warning')
                {{ __('super.logs_health.health_msg_warning', ['count' => $stats['unresolved']]) }}
            @else
                {{ __('super.logs_health.health_msg_critical', ['count' => $stats['critical']]) }}
            @endif
        </p>
    </div>
</div>

{{-- Stats cards --}}
<div class="logs-stats-grid">
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
    <div class="logs-stat-card">
        <div class="logs-stat-icon logs-stat-icon-24h"><i class="bi bi-clock-history"></i></div>
        <div>
            <p class="logs-stat-value">{{ number_format($stats['last_24h']) }}</p>
            <p class="logs-stat-label">{{ __('super.logs_health.last_24h') }}</p>
        </div>
    </div>
    <div class="logs-stat-card">
        <div class="logs-stat-icon logs-stat-icon-resolved"><i class="bi bi-check-circle"></i></div>
        <div>
            <p class="logs-stat-value">{{ number_format($stats['resolved']) }}</p>
            <p class="logs-stat-label">{{ __('super.logs_health.resolved') }}</p>
        </div>
    </div>
</div>

{{-- By type breakdown --}}
@if(! empty($stats['by_type']))
<div class="logs-type-breakdown">
    <p class="logs-type-breakdown-title">{{ __('super.logs_health.unresolved_by_type') }}</p>
    <div class="logs-type-chips">
        @foreach($types as $typeKey => $typeLabel)
            @if(! empty($stats['by_type'][$typeKey]))
                <a class="logs-type-chip logs-type-chip-{{ $typeKey }}" href="{{ route('super.logs-health.index', ['type' => $typeKey, 'status' => 'unresolved']) }}">
                    <span class="logs-type-chip-label">{{ __('super.logs_health.type_' . $typeKey) }}</span>
                    <span class="logs-type-chip-count">{{ $stats['by_type'][$typeKey] }}</span>
                </a>
            @endif
        @endforeach
    </div>
</div>
@endif

<div class="content-card">
    <div class="filter-bar">
        <form method="GET" action="{{ route('super.logs-health.index') }}" class="d-flex align-items-center gap-2 flex-wrap w-100" id="filterForm">
            <div class="search-box">
                <i class="bi bi-search"></i>
                <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="{{ __('super.logs_health.search_placeholder') }}" autocomplete="off">
            </div>
            <select class="form-control filter-select w-auto" name="type">
                <option value="">{{ __('super.logs_health.all_types') }}</option>
                @foreach($types as $key => $label)
                    <option value="{{ $key }}" {{ $filters['type'] === $key ? 'selected' : '' }}>{{ __('super.logs_health.type_' . $key) }}</option>
                @endforeach
            </select>
            <select class="form-control filter-select w-auto" name="status">
                <option value="">{{ __('super.logs_health.all_statuses') }}</option>
                <option value="unresolved" {{ $filters['status'] === 'unresolved' ? 'selected' : '' }}>{{ __('super.logs_health.unresolved') }}</option>
                <option value="resolved" {{ $filters['status'] === 'resolved' ? 'selected' : '' }}>{{ __('super.logs_health.resolved') }}</option>
            </select>
            <select class="form-control filter-select w-auto" name="tenant">
                <option value="">{{ __('super.logs_health.all_tenants') }}</option>
                @foreach($tenantOptions as $t)
                    <option value="{{ $t->id }}" {{ $filters['tenant'] === $t->id ? 'selected' : '' }}>
                        {{ $t->company_name ?? $t->id }}
                    </option>
                @endforeach
            </select>
            <input type="date" class="form-control filter-select w-auto" name="from" value="{{ $filters['from'] }}" title="{{ __('super.logs_health.from_date') }}">
            <input type="date" class="form-control filter-select w-auto" name="to" value="{{ $filters['to'] }}" title="{{ __('super.logs_health.to_date') }}">
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="bi bi-funnel"></i> {{ __('super.logs_health.apply') }}
            </button>
            @if($filters['search'] || $filters['type'] || $filters['status'] || $filters['tenant'] || $filters['from'] || $filters['to'])
                <a href="{{ route('super.logs-health.index') }}" class="btn btn-outline btn-sm">
                    <i class="bi bi-x-circle"></i> {{ __('super.logs_health.clear') }}
                </a>
            @endif
            <span class="text-muted small ms-auto">{{ $logs->total() }} {{ __('super.logs_health.entries') }}</span>
        </form>
    </div>

    <div class="table-responsive">
        <table class="logs-table">
            <thead>
                <tr>
                    <th class="action-cell action-cell-start"></th>
                    <th>{{ __('super.logs_health.col_severity') }}</th>
                    <th>{{ __('super.logs_health.col_type') }}</th>
                    <th>{{ __('super.logs_health.col_tenant') }}</th>
                    <th>{{ __('super.logs_health.col_message') }}</th>
                    <th>{{ __('super.logs_health.col_occurred_at') }}</th>
                    <th>{{ __('super.logs_health.col_status') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs as $log)
                    <tr class="log-row log-row-{{ $log->severity }}">
                        <td class="action-cell action-cell-start">
                            <div class="dropdown">
                                <button class="btn-icon" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="bi bi-three-dots-vertical"></i>
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="{{ route('super.logs-health.show', $log) }}"><i class="bi bi-eye"></i> {{ __('super.logs_health.view_details') }}</a></li>
                                    @if(! $log->isResolved())
                                        <li><button type="button" class="dropdown-item btn-log-action" data-action="resolve" data-url="{{ route('super.logs-health.resolve', $log) }}"><i class="bi bi-check2"></i> {{ __('super.logs_health.mark_resolved') }}</button></li>
                                        @if(in_array($log->type, ['dns', 'ssl', 'database', 'provisioning']))
                                            <li><button type="button" class="dropdown-item btn-log-action" data-action="recheck" data-url="{{ route('super.logs-health.recheck', $log) }}"><i class="bi bi-arrow-repeat"></i> {{ __('super.logs_health.recheck') }}</button></li>
                                        @endif
                                    @else
                                        <li><button type="button" class="dropdown-item btn-log-action" data-action="reopen" data-url="{{ route('super.logs-health.reopen', $log) }}"><i class="bi bi-arrow-counterclockwise"></i> {{ __('super.logs_health.reopen') }}</button></li>
                                    @endif
                                    @if($log->tenant_id)
                                        <li><a class="dropdown-item" href="{{ route('super.logs-health.tenant', $log->tenant_id) }}"><i class="bi bi-clock-history"></i> {{ __('super.logs_health.tenant_history') }}</a></li>
                                    @endif
                                    <li><hr class="dropdown-divider"></li>
                                    <li><button type="button" class="dropdown-item text-danger btn-log-action" data-action="delete" data-url="{{ route('super.logs-health.destroy', $log) }}"><i class="bi bi-trash"></i> {{ __('super.logs_health.delete') }}</button></li>
                                </ul>
                            </div>
                        </td>
                        <td>
                            <span class="severity-dot severity-dot-{{ $log->severity }}" title="{{ ucfirst($log->severity) }}"></span>
                            <span class="small text-muted">{{ __('super.logs_health.severity_' . $log->severity) }}</span>
                        </td>
                        <td>
                            <span class="log-type-badge log-type-badge-{{ $log->type }}">
                                {{ __('super.logs_health.type_' . $log->type) }}
                            </span>
                        </td>
                        <td>
                            @if($log->tenant_id)
                                <a href="{{ route('super.logs-health.tenant', $log->tenant_id) }}" class="tenant-link">
                                    {{ $log->tenant?->company_name ?? $log->tenant_id }}
                                </a>
                            @else
                                <span class="text-muted small">—</span>
                            @endif
                        </td>
                        <td class="log-message-cell">
                            <span class="log-message-text" title="{{ $log->message }}">{{ \Illuminate\Support\Str::limit($log->message, 100) }}</span>
                            @if($log->source)
                                <span class="log-source">{{ $log->source }}</span>
                            @endif
                        </td>
                        <td>
                            <span title="{{ $log->occurred_at?->toDateTimeString() }}">{{ $log->occurred_at?->diffForHumans() }}</span>
                        </td>
                        <td>
                            @if($log->isResolved())
                                <span class="badge-status badge-status-resolved">
                                    <i class="bi bi-check-circle-fill"></i> {{ __('super.logs_health.resolved') }}
                                </span>
                            @else
                                <span class="badge-status badge-status-unresolved">
                                    <i class="bi bi-circle-fill"></i> {{ __('super.logs_health.unresolved') }}
                                </span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="bi bi-journal-check fs-1 d-block mb-2 text-success"></i>
                            <p class="mb-1 fw-600">{{ __('super.logs_health.empty_title') }}</p>
                            <p class="small text-muted">{{ __('super.logs_health.empty_subtitle') }}</p>
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
