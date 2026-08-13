@extends('central.super.layout')

@section('title', __('super.logs_health.detail_title'))

@push('styles')
<link href="{{ asset('assets_super/css/logs-health.css') }}?v={{ time() }}" rel="stylesheet">
@endpush

@section('content')
<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <a href="{{ route('super.logs-health.index') }}" class="btn btn-outline btn-sm">
                <i class="bi bi-arrow-left"></i> {{ __('super.logs_health.back') }}
            </a>
            <span class="log-type-badge log-type-badge-{{ $log->type }}">
                {{ __('super.logs_health.type_' . $log->type) }}
            </span>
            @if($log->isResolved())
                <span class="badge-status badge-status-resolved"><i class="bi bi-check-circle-fill"></i> {{ __('super.logs_health.resolved') }}</span>
            @else
                <span class="badge-status badge-status-unresolved"><i class="bi bi-circle-fill"></i> {{ __('super.logs_health.unresolved') }}</span>
            @endif
        </div>
        <h1>{{ __('super.logs_health.detail_title') }}</h1>
        <p class="page-subtitle">#{{ $log->id }} · {{ __('super.logs_health.severity_' . $log->severity) }} · {{ $log->occurred_at?->toDateTimeString() }}</p>
    </div>
    <div class="d-flex gap-2">
        @if(! $log->isResolved())
            @if(in_array($log->type, ['dns', 'ssl', 'database', 'provisioning']))
                <button type="button" class="btn btn-outline btn-sm btn-log-action" data-action="recheck" data-url="{{ route('super.logs-health.recheck', $log) }}">
                    <i class="bi bi-arrow-repeat"></i> {{ __('super.logs_health.recheck') }}
                </button>
            @endif
            <button type="button" class="btn btn-primary btn-sm" id="btnResolveWithNote" data-url="{{ route('super.logs-health.resolve', $log) }}">
                <i class="bi bi-check2"></i> {{ __('super.logs_health.mark_resolved') }}
            </button>
        @else
            <button type="button" class="btn btn-outline btn-sm btn-log-action" data-action="reopen" data-url="{{ route('super.logs-health.reopen', $log) }}">
                <i class="bi bi-arrow-counterclockwise"></i> {{ __('super.logs_health.reopen') }}
            </button>
        @endif
        <button type="button" class="btn btn-outline-danger btn-sm btn-log-action" data-action="delete" data-url="{{ route('super.logs-health.destroy', $log) }}">
            <i class="bi bi-trash"></i> {{ __('super.logs_health.delete') }}
        </button>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="content-card log-detail-card">
            <div class="log-detail-section">
                <h3 class="log-detail-heading">{{ __('super.logs_health.error_message') }}</h3>
                <div class="log-detail-message log-detail-message-{{ $log->severity }}">
                    {{ $log->message }}
                </div>
            </div>

            @if(! empty($log->suggested_cause))
            <div class="log-detail-section">
                <h3 class="log-detail-heading"><i class="bi bi-lightbulb"></i> {{ __('super.logs_health.suggested_cause') }}</h3>
                <div class="log-detail-cause">{{ $log->suggested_cause }}</div>
            </div>
            @endif

            @if(! empty($log->context))
            <div class="log-detail-section">
                <h3 class="log-detail-heading">{{ __('super.logs_health.context') }}</h3>
                <pre class="log-detail-context">{{ json_encode($log->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            </div>
            @endif

            @if($log->isResolved())
            <div class="log-detail-section">
                <h3 class="log-detail-heading"><i class="bi bi-check-circle"></i> {{ __('super.logs_health.resolution') }}</h3>
                <div class="log-detail-resolution">
                    <p class="mb-1"><strong>{{ __('super.logs_health.resolved_by') }}:</strong> {{ $log->resolver?->name ?? '—' }}</p>
                    <p class="mb-1"><strong>{{ __('super.logs_health.resolved_at') }}:</strong> {{ $log->resolved_at?->toDateTimeString() }}</p>
                    @if($log->resolution_note)
                        <p class="mb-0 mt-2"><strong>{{ __('super.logs_health.note') }}:</strong></p>
                        <p class="mb-0 text-muted">{{ $log->resolution_note }}</p>
                    @endif
                </div>
            </div>
            @endif
        </div>
    </div>

    <div class="col-lg-4">
        <div class="content-card log-detail-card">
            <h3 class="log-detail-heading">{{ __('super.logs_health.metadata') }}</h3>
            <dl class="log-meta-list">
                <dt>{{ __('super.logs_health.meta_id') }}</dt>
                <dd>#{{ $log->id }}</dd>

                <dt>{{ __('super.logs_health.meta_tenant') }}</dt>
                <dd>
                    @if($log->tenant_id)
                        <a href="{{ route('super.logs-health.tenant', $log->tenant_id) }}">
                            {{ $log->tenant?->company_name ?? $log->tenant_id }}
                        </a>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </dd>

                <dt>{{ __('super.logs_health.meta_type') }}</dt>
                <dd>{{ __('super.logs_health.type_' . $log->type) }}</dd>

                <dt>{{ __('super.logs_health.meta_severity') }}</dt>
                <dd>
                    <span class="severity-dot severity-dot-{{ $log->severity }}"></span>
                    {{ __('super.logs_health.severity_' . $log->severity) }}
                </dd>

                <dt>{{ __('super.logs_health.meta_source') }}</dt>
                <dd>{{ $log->source ?? '—' }}</dd>

                <dt>{{ __('super.logs_health.meta_occurred') }}</dt>
                <dd>{{ $log->occurred_at?->toDateTimeString() }}</dd>

                <dt>{{ __('super.logs_health.meta_recorded') }}</dt>
                <dd>{{ $log->created_at?->toDateTimeString() }}</dd>
            </dl>
        </div>

        @if($tenantHistory->isNotEmpty())
        <div class="content-card log-detail-card mt-3">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h3 class="log-detail-heading mb-0">{{ __('super.logs_health.recent_tenant_logs') }}</h3>
                @if($log->tenant_id)
                    <a href="{{ route('super.logs-health.tenant', $log->tenant_id) }}" class="small">{{ __('super.logs_health.view_all') }}</a>
                @endif
            </div>
            <ul class="tenant-history-list">
                @foreach($tenantHistory as $h)
                    <li>
                        <a href="{{ route('super.logs-health.show', $h) }}" class="tenant-history-item">
                            <span class="severity-dot severity-dot-{{ $h->severity }}"></span>
                            <span class="tenant-history-type">{{ __('super.logs_health.type_' . $h->type) }}</span>
                            <span class="tenant-history-msg">{{ \Illuminate\Support\Str::limit($h->message, 60) }}</span>
                            <span class="tenant-history-time">{{ $h->occurred_at?->diffForHumans() }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
        @endif
    </div>
</div>

{{-- Resolve modal --}}
<div class="modal fade" id="resolveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">{{ __('super.logs_health.mark_resolved') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">{{ __('super.logs_health.resolve_hint') }}</p>
                <label class="form-label">{{ __('super.logs_health.note_optional') }}</label>
                <textarea class="form-control" id="resolveNote" rows="4" maxlength="2000" placeholder="{{ __('super.logs_health.note_placeholder') }}"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-bs-dismiss="modal">{{ __('super.common.cancel') }}</button>
                <button type="button" class="btn btn-primary" id="btnConfirmResolve">{{ __('super.logs_health.mark_resolved') }}</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('assets_super/js/logs-health.js') }}?v={{ time() }}"></script>
@endpush
