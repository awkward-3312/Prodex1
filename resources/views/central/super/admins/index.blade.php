@extends('central.super.layout')

@section('title', __('central.SuperAdmins'))

@section('content')
<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-3">
    <div>
        <h1>{{ __('central.SuperAdmins') }}</h1>
        <p class="page-subtitle">{{ __('central.ManageSuperAdminsSubtitle') }}</p>
    </div>
    <a href="{{ route('super.admins.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg"></i> {{ __('central.AddAdmin') }}
    </a>
</div>

<div class="content-card">
    <div class="card-body-custom p-0">
        <table class="table mb-0">
            <thead>
                <tr>
                    <th class="w-50px">#</th>
                    <th>{{ __('central.Name') }}</th>
                    <th>{{ __('central.Email') }}</th>
                    <th>{{ __('central.Role') }}</th>
                    <th>{{ __('central.Created') }}</th>
                    <th class="action-cell">{{ __('central.Actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($admins as $admin)
                <tr>
                    <td>
                        <span class="fw-600 text-muted">{{ $admin->id }}</span>
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="admin-avatar {{ ['admin-avatar-indigo','admin-avatar-blue','admin-avatar-green','admin-avatar-amber','admin-avatar-rose'][$admin->id % 5] }}">
                                {{ strtoupper(mb_substr($admin->name, 0, 1)) }}
                            </div>
                            <div>
                                <span class="fw-700 fs-md">{{ $admin->name }}</span>
                                @if($admin->id === $currentId)
                                    <span class="badge badge-active ms-1 badge-xs">{{ __('central.You') }}</span>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="text-muted fs-sm4">{{ $admin->email }}</span>
                    </td>
                    <td>
                        @if($admin->isSuperAdmin())
                            <span class="badge badge-role-super">{{ __('central.SuperAdmin') }}</span>
                        @else
                            <span class="badge badge-role-admin">{{ __('central.Admin') }}</span>
                            @if(!empty($admin->permissions))
                                <span class="d-block text-muted mt-1 fs-xs">{{ count($admin->permissions) }} {{ Str::plural(__('central.Section'), count($admin->permissions)) }}</span>
                            @else
                                <span class="d-block text-muted mt-1 fs-xs">{{ __('central.NoAccess') }}</span>
                            @endif
                        @endif
                    </td>
                    <td>
                        <span class="text-muted fs-sm3">{{ $admin->created_at->format('M d, Y') }}</span>
                    </td>
                    <td class="action-cell">
                        <div class="d-flex align-items-center justify-content-end gap-2">
                            <a href="{{ route('super.admins.edit', $admin) }}" class="btn btn-outline btn-sm">
                                <i class="bi bi-pencil-square"></i> {{ __('central.Edit') }}
                            </a>
                            @if($admin->id !== $currentId)
                            <form method="POST" action="{{ route('super.admins.destroy', $admin) }}" class="swal-confirm-form" data-swal-confirm="{{ json_encode(['title' => __('central.DeleteAdminTitle'), 'text' => __('central.DeleteAdminText', ['name' => $admin->name]), 'icon' => 'warning', 'confirmButtonText' => __('central.YesDelete'), 'confirmButtonColor' => '#ef4444']) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-outline btn-sm btn-outline-danger-soft">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">{{ __('central.NoAdminsFound') }}</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@push('scripts')
<script src="{{ asset('assets_super/js/admins-index.js') }}"></script>
@endpush

@endsection
