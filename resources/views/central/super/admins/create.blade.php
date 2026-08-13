@extends('central.super.layout')

@section('title', __('central.AddSuperAdmin'))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.admins.index') }}">{{ __('central.SuperAdmins') }}</a>
    <span class="separator">/</span>
    <span class="current">{{ __('central.AddAdmin') }}</span>
</div>

<div class="page-header">
    <h1>{{ __('central.AddSuperAdmin') }}</h1>
    <p class="page-subtitle">{{ __('central.AddSuperAdminSubtitle') }}</p>
</div>

<form method="POST" action="{{ route('super.admins.store') }}">
    @csrf

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-person-plus me-2 text-muted"></i>{{ __('central.AdminDetails') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('central.FullName') }}</label>
                                <input
                                    type="text"
                                    name="name"
                                    class="form-control @error('name') is-invalid @enderror"
                                    value="{{ old('name') }}"
                                    placeholder="{{ __('central.FullNamePlaceholder') }}"
                                    required
                                >
                                @error('name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('central.EmailAddress') }}</label>
                                <input
                                    type="email"
                                    name="email"
                                    class="form-control @error('email') is-invalid @enderror"
                                    value="{{ old('email') }}"
                                    placeholder="{{ __('central.EmailPlaceholder') }}"
                                    required
                                >
                                @error('email')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('central.Password') }}</label>
                                <div class="position-relative">
                                    <input
                                        type="password"
                                        name="password"
                                        class="form-control pe-5 @error('password') is-invalid @enderror"
                                        placeholder="{{ __('central.PasswordPlaceholder') }}"
                                        required
                                        minlength="8"
                                        autocomplete="new-password"
                                    >
                                    <button type="button" class="btn-pw-toggle" tabindex="-1"><i class="bi bi-eye"></i></button>
                                </div>
                                @error('password')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('central.ConfirmPassword') }}</label>
                                <div class="position-relative">
                                    <input
                                        type="password"
                                        name="password_confirmation"
                                        class="form-control pe-5"
                                        placeholder="{{ __('central.ReEnterPassword') }}"
                                        required
                                        minlength="8"
                                        autocomplete="new-password"
                                    >
                                    <button type="button" class="btn-pw-toggle" tabindex="-1"><i class="bi bi-eye"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="divider"></div>

                    {{-- Role selection --}}
                    <h3 class="fw-700 mb-1 section-subtitle">{{ __('central.RoleAndPermissions') }}</h3>
                    <p class="text-muted mb-3 section-desc">{{ __('central.RoleAndPermissionsDesc') }}</p>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('central.Role') }}</label>
                                <select name="role" id="roleSelect" class="form-control @error('role') is-invalid @enderror" required>
                                    <option value="super_admin" {{ old('role', 'super_admin') === 'super_admin' ? 'selected' : '' }}>{{ __('central.RoleSuperAdminOption') }}</option>
                                    <option value="admin" {{ old('role') === 'admin' ? 'selected' : '' }}>{{ __('central.RoleAdminOption') }}</option>
                                </select>
                                @error('role')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>

                    {{-- Permissions grid (shown only for admin role) --}}
                    <div id="permissionsBlock" class="permissions-block-hidden">
                        <label class="form-label mb-2">{{ __('central.AllowedSections') }}</label>
                        @error('permissions')
                            <div class="text-danger mb-2 section-desc">{{ $message }}</div>
                        @enderror
                        <div class="row g-2">
                            @foreach($permissions as $key => $label)
                            <div class="col-md-4 col-6">
                                <label class="perm-check-card">
                                    <input type="checkbox" name="permissions[]" value="{{ $key }}" {{ in_array($key, old('permissions', [])) ? 'checked' : '' }}>
                                    <span class="perm-check-label">
                                        <i class="bi bi-check-lg perm-check-icon"></i>
                                        {{ $label }}
                                    </span>
                                </label>
                            </div>
                            @endforeach
                        </div>
                        <p class="text-muted mt-2 fs-xs3">
                            <i class="bi bi-info-circle me-1"></i>
                            {{ __('central.OnlySelectedSectionsVisible') }}
                        </p>
                    </div>

                    <div class="d-flex align-items-center gap-3 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-lg"></i> {{ __('central.CreateAdmin') }}
                        </button>
                        <a href="{{ route('super.admins.index') }}" class="back-link">{{ __('central.Cancel') }}</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="content-card sticky-sidebar">
                <div class="card-header-custom">
                    <h2><i class="bi bi-shield-exclamation me-2 text-muted"></i>{{ __('central.Important') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="d-flex flex-column gap-3 sidebar-tip-list">
                        <div class="d-flex gap-2">
                            <i class="bi bi-person-fill-lock flex-shrink-0 color-primary"></i>
                            <span><strong>{{ __('central.SuperAdmin') }}</strong> {{ __('central.SuperAdminTipDesc') }}</span>
                        </div>
                        <div class="d-flex gap-2">
                            <i class="bi bi-person-fill-gear flex-shrink-0 color-info"></i>
                            <span><strong>{{ __('central.Admin') }}</strong> {{ __('central.AdminTipDesc') }}</span>
                        </div>
                        <div class="d-flex gap-2">
                            <i class="bi bi-lock-fill flex-shrink-0 color-warning"></i>
                            <span>{{ __('central.StrongPasswordTip') }}</span>
                        </div>
                        <div class="d-flex gap-2">
                            <i class="bi bi-envelope-fill flex-shrink-0 color-info"></i>
                            <span>{{ __('central.EmailLoginTip') }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

@push('scripts')
<script src="{{ asset('assets_super/js/admins-form.js') }}"></script>
@endpush
@endsection
