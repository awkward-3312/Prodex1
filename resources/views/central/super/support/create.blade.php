@extends('central.super.layout')

@section('title', __('super.support.create_title'))

@php
    use App\Models\Central\SupportTicket;
@endphp

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.support.index') }}">{{ __('super.support.title') }}</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ __('super.support.create_ticket') }}</span>
</div>

<div class="page-header">
    <h1>{{ __('super.support.create_title') }}</h1>
    <p class="page-subtitle">{{ __('super.support.create_desc') }}</p>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="content-card">
            <div class="card-header-custom">
                <h2><i class="bi bi-plus-circle me-2 text-muted"></i>{{ __('super.support.details') }}</h2>
            </div>
            <div class="card-body-custom">
                <form method="POST" action="{{ route('super.support.store') }}" enctype="multipart/form-data">
                    @csrf

                    <div class="form-row mb-4">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.support.tenant') }} <span class="text-danger">*</span></label>
                            <select name="tenant_id" class="form-control" required>
                                <option value="">{{ __('super.support.select_tenant') }}</option>
                                @foreach($tenants as $tenant)
                                    <option value="{{ $tenant->id }}" {{ old('tenant_id') === $tenant->id ? 'selected' : '' }}>
                                        {{ $tenant->company_name ?? $tenant->id }}
                                        @if($tenant->admin_email)
                                            — {{ $tenant->admin_email }}
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                            @error('tenant_id')<p class="text-danger form-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="form-group">
                            <label class="form-label">{{ __('super.support.assigned') }}</label>
                            <select name="assigned_to" class="form-control">
                                <option value="">{{ __('super.support.unassigned') }}</option>
                                @foreach($admins as $admin)
                                    <option value="{{ $admin->id }}" {{ (string) old('assigned_to') === (string) $admin->id ? 'selected' : '' }}>{{ $admin->name }}</option>
                                @endforeach
                            </select>
                            @error('assigned_to')<p class="text-danger form-error">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.support.subject') }} <span class="text-danger">*</span></label>
                        <input type="text" name="subject" class="form-control" value="{{ old('subject') }}" maxlength="255" required>
                        @error('subject')<p class="text-danger form-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="form-row mb-4">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.support.category') }} <span class="text-danger">*</span></label>
                            <select name="category" class="form-control" required>
                                @foreach(SupportTicket::CATEGORIES as $c)
                                    <option value="{{ $c }}" {{ old('category') === $c ? 'selected' : '' }}>{{ __('super.support.category_' . $c) }}</option>
                                @endforeach
                            </select>
                            @error('category')<p class="text-danger form-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="form-group">
                            <label class="form-label">{{ __('super.support.priority') }} <span class="text-danger">*</span></label>
                            <select name="priority" class="form-control" required>
                                @foreach(SupportTicket::PRIORITIES as $p)
                                    <option value="{{ $p }}" {{ old('priority', 'medium') === $p ? 'selected' : '' }}>{{ __('super.support.priority_' . $p) }}</option>
                                @endforeach
                            </select>
                            @error('priority')<p class="text-danger form-error">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.support.message') }} <span class="text-danger">*</span></label>
                        <textarea name="message" class="form-control" rows="6" placeholder="{{ __('super.support.message_placeholder') }}" required>{{ old('message') }}</textarea>
                        @error('message')<p class="text-danger form-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.support.attachments') }}</label>
                        <input type="file" name="attachments[]" class="form-control" multiple accept=".jpg,.jpeg,.png,.pdf">
                        @error('attachments.*')<p class="text-danger form-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="divider"></div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg"></i> {{ __('super.support.create_ticket') }}</button>
                        <a href="{{ route('super.support.index') }}" class="btn btn-secondary">{{ __('super.common.cancel') }}</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="content-card sticky-sidebar">
            <div class="card-body-custom">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <i class="bi bi-lightbulb text-muted fs-base"></i>
                    <h3 class="mb-0 fw-700 tips-card-title">{{ __('super.support.tips') }}</h3>
                </div>
                <div class="d-flex flex-column gap-3 tips-card-body">
                    <div class="d-flex gap-2">
                        <i class="bi bi-info-circle tips-icon-info"></i>
                        <span>{{ __('super.support.create_tip_1') }}</span>
                    </div>
                    <div class="d-flex gap-2">
                        <i class="bi bi-info-circle tips-icon-info"></i>
                        <span>{{ __('super.support.create_tip_2') }}</span>
                    </div>
                    <div class="d-flex gap-2">
                        <i class="bi bi-shield-check tips-icon-success"></i>
                        <span>{{ __('super.support.create_tip_3') }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
