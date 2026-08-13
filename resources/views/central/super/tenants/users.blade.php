@extends('central.super.layout')

@php
    $displayName = $tenant->company_name ?? ($tenant->domains->first()->domain ?? $tenant->id);
@endphp

@section('title', __('central.UsersFor', ['tenant' => $displayName]))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.tenants.index') }}">{{ __('super.tenants.title') }}</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <a href="{{ route('super.tenants.show', $tenant) }}">{{ $displayName }}</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ __('central.Users') }}</span>
</div>

<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div class="d-flex align-items-center gap-3">
        @php
            $colors = ['purple', 'blue', 'green', 'amber', 'rose'];
            $color = $colors[crc32($tenant->id) % count($colors)];
            $initial = strtoupper(mb_substr($displayName, 0, 1));
        @endphp
        <div class="tenant-avatar tenant-avatar-{{ $color }} tenant-avatar-lg">{{ $initial }}</div>
        <div>
            <h1 class="mb-0_1">{{ __('central.Users') }}</h1>
            <p class="page-subtitle mb-0">{{ __('central.ManageUsersForTenant', ['tenant' => $displayName]) }}</p>
        </div>
    </div>
    <a href="{{ route('super.tenants.show', $tenant) }}" class="btn btn-outline btn-sm">
        <i class="bi bi-arrow-left"></i> {{ __('central.BackToTenant') }}
    </a>
</div>

@if($error)
    <div class="content-card">
        <div class="card-body-custom text-center py-5">
            <i class="bi bi-exclamation-triangle text-warning fs-4xl"></i>
            <p class="text-muted mt-3 mb-0">{{ $error }}</p>
        </div>
    </div>
@else
    {{-- Filters --}}
    <div class="content-card mb-4">
        <div class="card-body-custom py-3">
            <form method="GET" action="{{ route('super.tenants.users', $tenant) }}" class="d-flex flex-wrap align-items-center gap-3">
                <div class="d-flex align-items-center gap-2 flex-grow-1 max-w-320">
                    <div class="input-icon-wrapper">
                        <i class="bi bi-search input-icon"></i>
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="{{ __('central.SearchUsersPlaceholder') }}" value="{{ request('search') }}">
                    </div>
                </div>
                <select name="status" class="form-select form-select-sm filter-select">
                    <option value="">{{ __('central.AllStatus') }}</option>
                    <option value="1" {{ request('status') === '1' ? 'selected' : '' }}>{{ __('central.Active') }}</option>
                    <option value="0" {{ request('status') === '0' ? 'selected' : '' }}>{{ __('central.Blocked') }}</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-funnel"></i> {{ __('central.Filter') }}
                </button>
                @if(request('search') || request('status') !== null && request('status') !== '')
                    <a href="{{ route('super.tenants.users', $tenant) }}" class="btn btn-ghost btn-sm">{{ __('central.Clear') }}</a>
                @endif
            </form>
        </div>
    </div>

    {{-- Stats --}}
    <div class="row g-3 mb-4">
        <div class="col-sm-4">
            <div class="content-card">
                <div class="card-body-custom py-3 d-flex align-items-center gap-3">
                    <div class="stat-icon-users stat-icon-primary">
                        <i class="bi bi-people-fill"></i>
                    </div>
                    <div>
                        <div class="stat-val-lg">{{ collect($users)->count() }}</div>
                        <div class="text-muted fs-xs3">{{ __('central.TotalUsers') }}</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="content-card">
                <div class="card-body-custom py-3 d-flex align-items-center gap-3">
                    <div class="stat-icon-users stat-icon-success">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>
                    <div>
                        <div class="stat-val-lg">{{ collect($users)->where('statut', 1)->count() }}</div>
                        <div class="text-muted fs-xs3">{{ __('central.Active') }}</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="content-card">
                <div class="card-body-custom py-3 d-flex align-items-center gap-3">
                    <div class="stat-icon-users stat-icon-danger">
                        <i class="bi bi-slash-circle-fill"></i>
                    </div>
                    <div>
                        <div class="stat-val-lg">{{ collect($users)->where('statut', 0)->count() }}</div>
                        <div class="text-muted fs-xs3">{{ __('central.Blocked') }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Users table --}}
    <div class="content-card">
        <div class="card-body-custom p-0">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th class="w-50px">#</th>
                        <th>{{ __('central.UserCol') }}</th>
                        <th>{{ __('central.EmailCol') }}</th>
                        <th>{{ __('central.Phone') }}</th>
                        <th>{{ __('central.Role') }}</th>
                        <th>{{ __('central.StatusCol') }}</th>
                        <th class="action-cell">{{ __('central.Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($users as $user)
                    <tr>
                        <td>
                            <span class="fw-600 text-muted">{{ $user->id }}</span>
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                @php
                                    $avatarColors = ['#6366f1','#3b82f6','#10b981','#f59e0b','#f43f5e'];
                                    $avatarGrads = ['#818cf8','#60a5fa','#34d399','#fbbf24','#fb7185'];
                                @endphp
                                <div class="user-avatar" style="background: linear-gradient(135deg, {{ $avatarColors[$user->id % 5] }}, {{ $avatarGrads[$user->id % 5] }});">
                                    {{ strtoupper(mb_substr($user->firstname, 0, 1)) }}{{ strtoupper(mb_substr($user->lastname, 0, 1)) }}
                                </div>
                                <div>
                                    <span class="fw-700 fs-md">{{ $user->firstname }} {{ $user->lastname }}</span>
                                    @if((int) $user->id === 1)
                                        <span class="badge badge-trial ms-1 badge-owner">{{ __('central.Owner') }}</span>
                                    @endif
                                    <div class="text-muted fs-xs3">{{ $user->username }}</div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="text-muted fs-sm4">{{ $user->email }}</span>
                        </td>
                        <td>
                            <span class="text-muted fs-sm4">{{ $user->phone ?: '—' }}</span>
                        </td>
                        <td>
                            @php $role = $user->roles->first(); @endphp
                            @if($role)
                                <span class="badge badge-trial badge-role">{{ $role->name }}</span>
                            @else
                                <span class="text-muted fs-sm2">—</span>
                            @endif
                        </td>
                        <td>
                            @if($user->statut)
                                <span class="badge badge-active">{{ __('central.Active') }}</span>
                            @else
                                <span class="badge badge-cancelled">{{ __('central.Blocked') }}</span>
                            @endif
                        </td>
                        <td class="action-cell">
                            <button type="button"
                                    class="btn btn-outline btn-sm"
                                    data-change-password-url="{{ route('super.tenants.users.password', [$tenant, $user->id]) }}"
                                    data-user-name="{{ $user->firstname }} {{ $user->lastname }}">
                                <i class="bi bi-key"></i> {{ __('central.ChangePassword') }}
                            </button>
                            @if($user->statut)
                                <form method="POST" action="{{ route('super.tenants.users.block', [$tenant, $user->id]) }}" class="d-inline" data-swal-confirm="{{ json_encode(['title' => __('central.BlockUserTitle'), 'text' => __('central.BlockUserText', ['name' => $user->firstname . ' ' . $user->lastname]), 'icon' => 'warning', 'confirmButtonText' => __('central.YesBlock'), 'confirmButtonColor' => '#ef4444']) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-outline btn-sm btn-outline-danger-soft">
                                        <i class="bi bi-slash-circle"></i> {{ __('central.Block') }}
                                    </button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('super.tenants.users.unblock', [$tenant, $user->id]) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-outline btn-sm btn-outline-success-soft">
                                        <i class="bi bi-check-circle"></i> {{ __('central.Unblock') }}
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            <i class="bi bi-people fs-xl3"></i>
                            <p class="mt-2 mb-0">{{ __('central.NoUsersFound') }}</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif

<div class="mt-4">
    <a href="{{ route('super.tenants.show', $tenant) }}" class="back-link">
        <i class="bi bi-arrow-left"></i> {{ __('central.BackTo', ['name' => $displayName]) }}
    </a>
</div>

@push('scripts')
<script>
window.TenantUserPasswordData = {
    csrfToken: '{{ csrf_token() }}',
    trans: {
        title:           @json(__('central.ChangePasswordTitle')),
        subtitle:        @json(__('central.ChangePasswordSubtitle')),
        newPassword:     @json(__('central.NewPassword')),
        confirmPassword: @json(__('central.ConfirmNewPassword')),
        confirm:         @json(__('central.ChangePassword')),
        cancel:          @json(__('central.Cancel')),
        tooShort:        @json(__('central.PasswordTooShort')),
        mismatch:        @json(__('central.PasswordsDoNotMatch')),
    },
};
</script>
<script src="{{ asset('assets_super/js/swal-confirm-form.js') }}"></script>
<script src="{{ asset('assets_super/js/tenant-user-password.js') }}"></script>
@endpush
@endsection
