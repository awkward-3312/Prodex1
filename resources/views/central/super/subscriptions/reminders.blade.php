@extends('central.super.layout')

@section('title', __('super.reminders.title'))

@section('content')
<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
        <h1>{{ __('super.reminders.title') }}</h1>
        <p class="page-subtitle">{{ __('super.reminders.subtitle') }}</p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('super.settings.general') }}#tab=reminders" class="btn btn-outline btn-sm">
            <i class="bi bi-gear"></i> {{ __('super.reminders.settings') }}
        </a>
        <a href="{{ route('super.subscriptions.reminders') }}" class="btn btn-outline btn-sm">
            <i class="bi bi-arrow-clockwise"></i> {{ __('super.common.refresh') }}
        </a>
    </div>
</div>

<div class="content-card">
    <div class="filter-bar">
        <form method="GET" action="{{ route('super.subscriptions.reminders') }}" class="d-flex align-items-center gap-3 flex-wrap w-100">
            <div class="search-box">
                <i class="bi bi-search"></i>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="{{ __('super.reminders.search') }}" autocomplete="off">
            </div>
            <select class="form-control filter-select w-auto" name="type" onchange="this.form.submit()">
                <option value="">{{ __('super.reminders.all_types') }}</option>
                <option value="expiry"     {{ request('type') === 'expiry' ? 'selected' : '' }}>{{ __('super.reminders.type_expiry') }}</option>
                <option value="trial"      {{ request('type') === 'trial' ? 'selected' : '' }}>{{ __('super.reminders.type_trial') }}</option>
                <option value="expired"    {{ request('type') === 'expired' ? 'selected' : '' }}>{{ __('super.reminders.type_expired') }}</option>
                <option value="plan_ended" {{ request('type') === 'plan_ended' ? 'selected' : '' }}>{{ __('super.reminders.type_plan_ended') }}</option>
            </select>
            <select class="form-control filter-select w-auto" name="status" onchange="this.form.submit()">
                <option value="">{{ __('super.reminders.all_statuses') }}</option>
                <option value="sent"    {{ request('status') === 'sent' ? 'selected' : '' }}>{{ __('super.reminders.status_sent') }}</option>
                <option value="failed"  {{ request('status') === 'failed' ? 'selected' : '' }}>{{ __('super.reminders.status_failed') }}</option>
                <option value="skipped" {{ request('status') === 'skipped' ? 'selected' : '' }}>{{ __('super.reminders.status_skipped') }}</option>
            </select>
            <span class="text-muted small ms-auto">{{ $reminders->total() }} {{ Str::plural('reminder', $reminders->total()) }}</span>
        </form>
    </div>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>{{ __('super.reminders.tenant') }}</th>
                    <th>{{ __('super.reminders.type') }}</th>
                    <th>{{ __('super.reminders.channel') }}</th>
                    <th>{{ __('super.reminders.timing') }}</th>
                    <th>{{ __('super.reminders.target') }}</th>
                    <th>{{ __('super.common.status') }}</th>
                    <th>{{ __('super.reminders.sent_at') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($reminders as $r)
                @php
                    $tenantName = $r->tenant->company_name ?? $r->tenant_id ?? '—';
                    $colors = ['purple', 'blue', 'green', 'amber', 'rose'];
                    $color = $colors[crc32((string) $r->tenant_id) % count($colors)];
                    $initial = strtoupper(mb_substr($tenantName, 0, 1));
                    $statusColor = ['sent' => 'success', 'failed' => 'danger', 'skipped' => 'warning'][$r->status] ?? 'secondary';
                @endphp
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-3">
                            <div class="tenant-avatar tenant-avatar-{{ $color }} tenant-avatar-sm">{{ $initial }}</div>
                            <div>
                                <span class="fw-600 d-block fs-md">{{ $tenantName }}</span>
                                @if($r->subscription && $r->subscription->plan)
                                    <span class="text-muted fs-xs2">{{ $r->subscription->plan->name }}</span>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td><span class="badge badge-info">{{ __('super.reminders.type_' . $r->type) }}</span></td>
                    <td><span class="text-uppercase-label-sm">{{ __('super.reminders.channel_' . $r->channel) }}</span></td>
                    <td>
                        @if(in_array($r->type, ['expiry', 'trial']) && $r->offset_days > 0)
                            <span class="fs-sm3">{{ __('super.reminders.days_before', ['n' => $r->offset_days]) }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                        @if($r->reference_date)
                            <span class="d-block text-muted fs-xs2">{{ $r->reference_date->format('M d, Y') }}</span>
                        @endif
                    </td>
                    <td><span class="fs-sm3">{{ $r->target ?? '—' }}</span></td>
                    <td>
                        <span class="badge badge-{{ $statusColor }}">{{ __('super.reminders.status_' . $r->status) }}</span>
                        @if($r->status === 'failed' && $r->error)
                            <span class="d-block text-muted fs-xs2" title="{{ $r->error }}">{{ Str::limit($r->error, 40) }}</span>
                        @endif
                    </td>
                    <td>
                        @if($r->sent_at)
                            <span class="fs-sm3">{{ $r->sent_at->format('M d, Y H:i') }}</span>
                            <span class="d-block text-muted fs-xs2">{{ $r->sent_at->diffForHumans() }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7">
                        <div class="empty-state">
                            <div class="empty-icon"><i class="bi bi-bell-slash"></i></div>
                            <h3>{{ __('super.reminders.empty') }}</h3>
                            <p>{{ __('super.reminders.empty_desc') }}</p>
                        </div>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($reminders->hasPages())
<div class="mt-4 d-flex justify-content-center">
    {{ $reminders->links('vendor.pagination.bootstrap-5') }}
</div>
@endif
@endsection
