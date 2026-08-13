@extends('central.super.layout')

@section('title', __('super.stats.title'))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.cms.index') }}">CMS</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ __('super.stats.title') }}</span>
</div>

<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
        <h1>{{ __('super.stats.title') }}</h1>
        <p class="page-subtitle">{{ __('super.stats.subtitle') }}</p>
    </div>
    <a href="{{ route('super.cms.stats.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> {{ __('super.stats.add') }}</a>
</div>

<div class="content-card">
    <div class="card-header-custom">
        <h2><i class="bi bi-bar-chart me-2 text-muted"></i>{{ __('super.stats.items') }}</h2>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th class="w-50px">#</th>
                    <th>{{ __('super.stats.field_value') }}</th>
                    <th>{{ __('super.stats.field_label') }}</th>
                    <th>{{ __('super.stats.field_icon') }}</th>
                    <th>{{ __('super.common.status') }}</th>
                    <th class="action-cell">{{ __('super.common.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($stats as $stat)
                <tr>
                    <td>{{ $stat->sort_order }}</td>
                    <td class="fw-700">{{ $stat->value }}</td>
                    <td>{{ $stat->label }}</td>
                    <td>
                        @if($stat->icon)
                            <i class="{{ $stat->icon }} text-muted"></i> {{ $stat->icon }}
                        @else
                            <span class="text-muted">&mdash;</span>
                        @endif
                    </td>
                    <td><span class="badge badge-{{ $stat->is_active ? 'yes' : 'no' }}">{{ $stat->is_active ? __('super.common.active') : __('super.common.inactive') }}</span></td>
                    <td class="action-cell">
                        <a href="{{ route('super.cms.stats.edit', $stat) }}" class="btn btn-outline btn-sm">{{ __('super.common.edit') }}</a>
                        <form method="POST" action="{{ route('super.cms.stats.destroy', $stat) }}" class="d-inline swal-confirm-form" data-swal-confirm="{{ json_encode(['title' => __('super.common.delete') . '?', 'text' => __('super.stats.delete_confirm'), 'icon' => 'warning', 'confirmButtonText' => __('super.common.delete'), 'confirmButtonColor' => '#ef4444']) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline btn-sm text-danger">{{ __('super.common.delete') }}</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="text-center py-4 text-muted">{{ __('super.stats.no_stats') }} <a href="{{ route('super.cms.stats.create') }}">{{ __('super.stats.add_one') }}</a></td>
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
