@extends('central.super.layout')

@section('title', $ticket->ticket_number)

@php
    use App\Models\Central\SupportTicket;

    $statusBadge = fn ($s) => match ($s) {
        'open' => 'no', 'pending' => 'pending', 'resolved' => 'yes', 'closed' => 'info', default => 'pending',
    };
    $priorityBadge = fn ($p) => match ($p) {
        'urgent' => 'no', 'high' => 'pending', 'medium' => 'info', 'low' => 'yes', default => 'info',
    };
@endphp

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.support.index') }}">{{ __('super.support.title') }}</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ $ticket->ticket_number }}</span>
</div>

<div class="page-header d-flex flex-wrap align-items-start justify-content-between gap-3">
    <div>
        <h1>{{ $ticket->subject }}</h1>
        <p class="page-subtitle">
            <span class="badge badge-{{ $statusBadge($ticket->status) }}">{{ __('super.support.status_' . $ticket->status) }}</span>
            <span class="badge badge-{{ $priorityBadge($ticket->priority) }}">{{ __('super.support.priority_' . $ticket->priority) }}</span>
            &middot; {{ $ticket->ticket_number }}
            &middot; {{ $ticket->created_at->format('M d, Y \a\t h:i A') }}
        </p>
    </div>
</div>

<div class="row g-4">
    {{-- Conversation --}}
    <div class="col-lg-8">
        <div class="content-card">
            <div class="card-header-custom">
                <h2><i class="bi bi-chat-dots me-2 text-muted"></i>{{ __('super.support.conversation') }}</h2>
            </div>
            <div class="card-body-custom">
                @forelse($ticket->messages as $message)
                    @php $isAdmin = $message->fromAdmin(); @endphp
                    <div class="st-msg {{ $isAdmin ? 'st-msg-admin' : 'st-msg-tenant' }} {{ $message->is_internal_note ? 'st-msg-note' : '' }}">
                        <div class="st-msg-head">
                            <span class="fw-600">{{ $message->author_name ?? ($isAdmin ? __('super.support.support_team') : __('super.support.tenant')) }}</span>
                            @if($message->is_internal_note)
                                <span class="badge badge-pending ms-1">{{ __('super.support.internal_note') }}</span>
                            @else
                                <span class="badge {{ $isAdmin ? 'badge-info' : 'badge-yes' }} ms-1">{{ $isAdmin ? __('super.support.support_team') : __('super.support.tenant') }}</span>
                            @endif
                            <span class="text-muted fs-2xs ms-2">{{ $message->created_at->format('M d, Y h:i A') }}</span>
                        </div>
                        <div class="st-msg-body">{!! nl2br(e($message->body)) !!}</div>
                        @if($message->attachments->isNotEmpty())
                            <div class="st-msg-files">
                                @foreach($message->attachments as $att)
                                    <a href="{{ route('super.support.attachment.download', $att) }}" class="st-file">
                                        <i class="bi bi-paperclip"></i> {{ $att->original_name ?? basename($att->file_path) }}
                                        <span class="text-muted fs-2xs">({{ number_format($att->size / 1024, 0) }} KB)</span>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="text-muted">{{ __('super.support.no_messages') }}</p>
                @endforelse
            </div>
        </div>

        {{-- Reply --}}
        <div class="content-card mt-4">
            <div class="card-header-custom">
                <h2><i class="bi bi-reply me-2 text-muted"></i>{{ __('super.support.reply') }}</h2>
            </div>
            <div class="card-body-custom">
                <form method="POST" action="{{ route('super.support.reply', $ticket) }}" enctype="multipart/form-data">
                    @csrf
                    <textarea name="body" class="form-control" rows="4" placeholder="{{ __('super.support.reply_placeholder') }}" required>{{ old('body') }}</textarea>
                    <div class="mt-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <input type="file" name="attachments[]" class="form-control" style="max-width:340px;" accept=".jpg,.jpeg,.png,.pdf" multiple>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i> {{ __('super.support.send_reply') }}</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Internal note --}}
        <div class="content-card mt-4">
            <div class="card-header-custom">
                <h2><i class="bi bi-lock me-2 text-muted"></i>{{ __('super.support.internal_note') }}</h2>
            </div>
            <div class="card-body-custom">
                <form method="POST" action="{{ route('super.support.note', $ticket) }}">
                    @csrf
                    <textarea name="body" class="form-control" rows="2" placeholder="{{ __('super.support.note_placeholder') }}" required>{{ old('body') }}</textarea>
                    <div class="mt-3 text-end">
                        <button type="submit" class="btn btn-secondary"><i class="bi bi-plus-lg"></i> {{ __('super.support.add_note') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Sidebar --}}
    <div class="col-lg-4">
        <div class="content-card">
            <div class="card-header-custom"><h2>{{ __('super.support.details') }}</h2></div>
            <div class="card-body-custom">
                <dl class="st-meta">
                    <dt>{{ __('super.support.tenant') }}</dt>
                    <dd>
                        <span class="fw-600">{{ $ticket->tenant->company_name ?? $ticket->tenant_id }}</span>
                        <div class="text-muted fs-2xs">{{ $ticket->tenant_id }}</div>
                    </dd>
                    <dt>{{ __('super.support.opened_by') }}</dt>
                    <dd>{{ $ticket->opened_by_name ?? '—' }}<div class="text-muted fs-2xs">{{ $ticket->opened_by_email }}</div></dd>
                    <dt>{{ __('super.support.category') }}</dt>
                    <dd>{{ __('super.support.category_' . $ticket->category) }}</dd>
                    <dt>{{ __('super.support.priority') }}</dt>
                    <dd><span class="badge badge-{{ $priorityBadge($ticket->priority) }}">{{ __('super.support.priority_' . $ticket->priority) }}</span></dd>
                </dl>
            </div>
        </div>

        {{-- Status --}}
        <div class="content-card mt-4">
            <div class="card-header-custom"><h2>{{ __('super.support.change_status') }}</h2></div>
            <div class="card-body-custom">
                <form method="POST" action="{{ route('super.support.status', $ticket) }}" class="d-flex gap-2">
                    @csrf
                    <select name="status" class="form-control">
                        @foreach(SupportTicket::STATUSES as $s)
                            <option value="{{ $s }}" {{ $ticket->status === $s ? 'selected' : '' }}>{{ __('super.support.status_' . $s) }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-primary">{{ __('super.common.save') }}</button>
                </form>
            </div>
        </div>

        {{-- Assignment --}}
        <div class="content-card mt-4">
            <div class="card-header-custom"><h2>{{ __('super.support.assign') }}</h2></div>
            <div class="card-body-custom">
                <form method="POST" action="{{ route('super.support.assign', $ticket) }}" class="d-flex gap-2">
                    @csrf
                    <select name="assigned_to" class="form-control">
                        <option value="">{{ __('super.support.unassigned') }}</option>
                        @foreach($admins as $admin)
                            <option value="{{ $admin->id }}" {{ (string) $ticket->assigned_to === (string) $admin->id ? 'selected' : '' }}>{{ $admin->name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-primary">{{ __('super.common.save') }}</button>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
    .st-msg { padding: 14px 16px; border-radius: 10px; margin-bottom: 14px; border: 1px solid var(--border-color, #e5e7eb); }
    .st-msg-tenant { background: var(--surface-2, #f8fafc); }
    .st-msg-admin { background: rgba(13,110,253,.06); border-color: rgba(13,110,253,.18); }
    .st-msg-note { background: rgba(255,193,7,.10); border-color: rgba(255,193,7,.30); }
    .st-msg-head { display: flex; align-items: center; flex-wrap: wrap; margin-bottom: 8px; }
    .st-msg-body { white-space: normal; line-height: 1.55; }
    .st-msg-files { margin-top: 10px; display: flex; flex-direction: column; gap: 6px; }
    .st-file { display: inline-flex; align-items: center; gap: 6px; font-size: .85rem; }
    .st-meta dt { font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted, #6b7280); margin-top: 12px; }
    .st-meta dt:first-child { margin-top: 0; }
    .st-meta dd { margin: 2px 0 0; }
</style>
@endsection
