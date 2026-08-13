@extends('central.super.layout')

@section('title', __('super.tenants.edit_title'))

@section('content')
@php
    $languages = \App\Models\Central\CentralLanguage::active();
    $defaultLocale = \App\Models\Central\CentralLanguage::defaultLocale();
    $tenantDisplayName = $tenant->company_name ?? ($tenant->domains->first()->domain ?? $tenant->id);
@endphp
<div class="breadcrumb-custom">
    <a href="{{ route('super.tenants.index') }}">{{ __('super.tenants.title') }}</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <a href="{{ route('super.tenants.show', $tenant) }}">{{ $tenantDisplayName }}</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ __('super.common.edit') }}</span>
</div>

<div class="page-header">
    <h1>{{ __('super.tenants.edit_tenant') }}</h1>
    <p class="page-subtitle">{{ __('super.tenants.update_status_desc') }} <strong>{{ $tenantDisplayName }}</strong>.</p>
</div>

<div class="row g-4">
    {{-- Status --}}
    <div class="col-lg-7">
        <div class="content-card">
            <div class="card-header-custom">
                <h2><i class="bi bi-sliders me-2 text-muted"></i>{{ __('super.tenants.tenant_status') }}</h2>
            </div>
            <div class="card-body-custom">
                @php
                    $isProvisioned = in_array($tenant->status, ['active', 'suspended', 'cancelled']);
                @endphp
                <form method="POST" action="{{ route('super.tenants.update', $tenant) }}">
                    @csrf
                    @method('PUT')
                    <div class="form-group">
                        <label class="form-label">{{ __('super.tenants.current_status') }}</label>
                        <div class="mb-3">
                            <span class="badge badge-{{ $tenant->status }}">{{ ucfirst($tenant->status) }}</span>
                        </div>
                    </div>
                    @if(! $isProvisioned)
                    <div class="mb-3 p-3 rounded-3 approve-notice-amber">
                        <div class="d-flex gap-2">
                            <i class="bi bi-info-circle-fill flex-shrink-0 approve-notice-amber-icon"></i>
                            <p class="mb-0 approve-notice-amber-text">
                                {!! __('super.tenants.activate_requires_provision', ['url' => route('super.tenants.show', $tenant)]) !!}
                            </p>
                        </div>
                    </div>
                    @endif
                    <div class="form-group">
                        <label class="form-label">{{ __('super.tenants.change_status') }} <span class="text-danger">*</span></label>
                        <select name="status" class="form-control" required>
                            <option value="{{ $tenant->status }}" selected>{{ __('super.status.' . $tenant->status) }} ({{ __('super.subscriptions.current') }})</option>
                            @if($isProvisioned)
                                @if($tenant->status !== 'active')
                                    <option value="active">{{ __('super.status.active') }}</option>
                                @endif
                                @if($tenant->status !== 'suspended')
                                    <option value="suspended">{{ __('super.status.suspended') }}</option>
                                @endif
                            @endif
                            @if($tenant->status !== 'cancelled')
                                <option value="cancelled">{{ __('super.status.cancelled') }}</option>
                            @endif
                        </select>
                        <p class="form-hint">{{ __('super.tenants.status_warning') }}</p>
                    </div>

                    @if($languages->count() > 1)
                    <div class="form-group">
                        <label class="form-label">{{ __('central.EmailLanguage') }}</label>
                        <select name="locale" class="form-control">
                            @foreach($languages as $lang)
                                <option value="{{ $lang->locale }}" {{ ($tenant->locale ?? $defaultLocale) === $lang->locale ? 'selected' : '' }}>
                                    {{ $lang->name }}{{ $lang->is_default ? ' (' . __('central.Default') . ')' : '' }}
                                </option>
                            @endforeach
                        </select>
                        <p class="form-hint">{{ __('central.EmailLanguageHint') }}</p>
                    </div>
                    @endif
                    <div class="form-group">
                        <label class="form-label">{{ __('central.OwnerPhone') }}</label>
                        <input
                            type="tel"
                            name="owner_phone"
                            class="form-control @error('owner_phone') is-invalid @enderror"
                            value="{{ old('owner_phone', $tenant->owner_phone) }}"
                            placeholder="+1 555 123 4567"
                        >
                        <p class="form-hint">{{ __('central.OwnerPhoneHint') }}</p>
                        @error('owner_phone')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i> {{ __('super.common.save_changes') }}
                        </button>
                        <a href="{{ route('super.tenants.show', $tenant) }}" class="btn btn-secondary">{{ __('super.common.cancel') }}</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Domains --}}
    <div class="col-lg-5">
        <div class="content-card">
            <div class="card-header-custom">
                <h2><i class="bi bi-globe me-2 text-muted"></i>{{ __('super.tenants.domains') }}</h2>
            </div>
            <div class="card-body-custom">
                @if($tenant->domains->isNotEmpty())
                    <div class="d-flex flex-column gap-2 mb-4">
                        @foreach($tenant->domains as $d)
                        <div class="d-flex align-items-center justify-content-between p-3 rounded-3 domain-row">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-link-45deg text-muted"></i>
                                <span class="text-mono fw-500 fs-sm3">{{ $d->domain }}</span>
                            </div>
                            @if($tenant->domains->count() > 1)
                            <form method="POST" action="{{ route('super.tenants.domains.destroy', [$tenant, $d->id]) }}" data-swal-confirm="{{ json_encode(['title' => __('super.tenants.remove_domain'), 'text' => $d->domain, 'icon' => 'warning', 'confirmButtonText' => 'Yes, remove', 'confirmButtonColor' => '#ef4444']) }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-xs btn-ghost btn-ghost-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                            @endif
                        </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-center py-3 mb-3">
                        <p class="text-muted mb-0 small">{{ __('super.tenants.no_domains_config') }}</p>
                    </div>
                @endif

                <div class="divider"></div>

                <form method="POST" action="{{ route('super.tenants.domains.store', $tenant) }}">
                    @csrf
                    <div class="form-group">
                        <label class="form-label">{{ __('super.tenants.add_new_domain') }}</label>
                        <input type="text" name="domain" class="form-control" placeholder="app.customer.com" required>
                        <p class="form-hint">{{ __('super.tenants.domain_hint') }}</p>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-plus-lg"></i> {{ __('super.tenants.add_domain') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="mt-4">
    <a href="{{ route('super.tenants.show', $tenant) }}" class="back-link">
        <i class="bi bi-arrow-left"></i> {{ __('super.tenants.back_to_tenant') }}
    </a>
</div>
@push('scripts')
<script src="{{ asset('assets_super/js/swal-confirm-form.js') }}"></script>
@endpush
@endsection
