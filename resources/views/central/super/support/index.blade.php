@extends('central.super.layout')

@section('title', __('super.support.title'))

@php
    use App\Models\Central\SupportTicket;

    $statusBadge = fn ($s) => match ($s) {
        'open'     => 'no',
        'pending'  => 'pending',
        'resolved' => 'yes',
        'closed'   => 'info',
        default    => 'pending',
    };
    $priorityBadge = fn ($p) => match ($p) {
        'urgent' => 'no',
        'high'   => 'pending',
        'medium' => 'info',
        'low'    => 'yes',
        default  => 'info',
    };
@endphp

@section('content')
<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
        <h1>{{ __('super.support.title') }}</h1>
        <p class="page-subtitle">{{ __('super.support.subtitle') }}</p>
    </div>
    <a href="{{ route('super.support.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg"></i> {{ __('super.support.create_ticket') }}
    </a>
</div>

{{-- Stats --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="content-card h-100"><div class="card-body-custom">
            <div class="text-muted fs-sm2">{{ __('super.support.status_open') }}</div>
            <div class="fw-700 fs-3">{{ $stats['open'] }}</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="content-card h-100"><div class="card-body-custom">
            <div class="text-muted fs-sm2">{{ __('super.support.status_pending') }}</div>
            <div class="fw-700 fs-3">{{ $stats['pending'] }}</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="content-card h-100"><div class="card-body-custom">
            <div class="text-muted fs-sm2">{{ __('super.support.status_resolved') }}</div>
            <div class="fw-700 fs-3">{{ $stats['resolved'] }}</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="content-card h-100"><div class="card-body-custom">
            <div class="text-muted fs-sm2">{{ __('super.support.unread') }}</div>
            <div class="fw-700 fs-3">{{ $stats['unread'] }}</div>
        </div></div>
    </div>
</div>

{{-- Filters --}}
<div class="content-card mb-4">
    <div class="card-body-custom">
        <form method="GET" action="{{ route('super.support.index') }}">
            <div class="row g-3">
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control" placeholder="{{ __('super.support.search') }}" value="{{ request('search') }}">
                </div>
                <div class="col-md-2">
                    <select name="status" class="form-control">
                        <option value="">{{ __('super.support.all_statuses') }}</option>
                        @foreach(SupportTicket::STATUSES as $s)
                            <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ __('super.support.status_' . $s) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="category" class="form-control">
                        <option value="">{{ __('super.support.all_categories') }}</option>
                        @foreach(SupportTicket::CATEGORIES as $c)
                            <option value="{{ $c }}" {{ request('category') === $c ? 'selected' : '' }}>{{ __('super.support.category_' . $c) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="priority" class="form-control">
                        <option value="">{{ __('super.support.all_priorities') }}</option>
                        @foreach(SupportTicket::PRIORITIES as $p)
                            <option value="{{ $p }}" {{ request('priority') === $p ? 'selected' : '' }}>{{ __('super.support.priority_' . $p) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="assigned_to" class="form-control">
                        <option value="">{{ __('super.support.all_agents') }}</option>
                        <option value="unassigned" {{ request('assigned_to') === 'unassigned' ? 'selected' : '' }}>{{ __('super.support.unassigned') }}</option>
                        @foreach($admins as $admin)
                            <option value="{{ $admin->id }}" {{ (string) request('assigned_to') === (string) $admin->id ? 'selected' : '' }}>{{ $admin->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i> {{ __('super.common.filter') }}</button>
                <a href="{{ route('super.support.index') }}" class="btn btn-secondary btn-sm">{{ __('super.common.clear') }}</a>
            </div>
        </form>
    </div>
</div>

{{-- Table --}}
<div class="content-card">
    <div class="card-header-custom">
        <h2><i class="bi bi-life-preserver me-2 text-muted"></i>{{ __('super.support.tickets') }}</h2>
        <span class="text-muted fs-sm2">{{ $tickets->total() }} {{ Str::plural(__('super.common.record'), $tickets->total()) }}</span>
    </div>
    @if($tickets->isNotEmpty())
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>{{ __('super.support.ticket') }}</th>
                    <th>{{ __('super.support.tenant') }}</th>
                    <th>{{ __('super.support.category') }}</th>
                    <th>{{ __('super.support.priority') }}</th>
                    <th>{{ __('super.common.status') }}</th>
                    <th>{{ __('super.support.assigned') }}</th>
                    <th>{{ __('super.support.last_activity') }}</th>
                    <th class="action-cell">{{ __('super.common.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($tickets as $ticket)
                <tr>
                    <td>
                        <a href="{{ route('super.support.show', $ticket) }}" class="fw-600 table-link-primary">
                            {{ $ticket->ticket_number }}
                            @if($ticket->admin_unread)
                                <span class="badge badge-no ms-1">{{ __('super.support.new') }}</span>
                            @endif
                        </a>
                        <div class="text-muted fs-sm2 text-truncate" style="max-width:280px;">{{ $ticket->subject }}</div>
                    </td>
                    <td><span class="fw-600 fs-sm3">{{ $ticket->tenant->company_name ?? $ticket->tenant_id }}</span></td>
                    <td class="fs-sm2">{{ __('super.support.category_' . $ticket->category) }}</td>
                    <td><span class="badge badge-{{ $priorityBadge($ticket->priority) }}">{{ __('super.support.priority_' . $ticket->priority) }}</span></td>
                    <td><span class="badge badge-{{ $statusBadge($ticket->status) }}">{{ __('super.support.status_' . $ticket->status) }}</span></td>
                    <td class="fs-sm2">{{ $ticket->assignedAdmin->name ?? '—' }}</td>
                    <td class="fs-sm2">{{ optional($ticket->last_reply_at ?? $ticket->created_at)->diffForHumans() }}</td>
                    <td class="action-cell">
                        <div class="d-flex gap-1 justify-content-end">
                            <a href="{{ route('super.support.show', $ticket) }}" class="btn btn-outline btn-sm" title="{{ __('super.common.view') }}"><i class="bi bi-eye"></i></a>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="card-body-custom">
        {{ $tickets->links('vendor.pagination.bootstrap-5') }}
    </div>
    @else
    <div class="empty-state">
        <div class="empty-icon"><i class="bi bi-life-preserver"></i></div>
        <h3>{{ __('super.support.no_tickets') }}</h3>
        <p>{{ __('super.support.no_tickets_desc') }}</p>
    </div>
    @endif
</div>
@endsection
