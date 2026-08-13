@extends('central.super.layout')

@section('title', __('super.features.title'))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.cms.index') }}">CMS</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ __('super.features.title') }}</span>
</div>

<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
        <h1>{{ __('super.features.title') }}</h1>
        <p class="page-subtitle">{{ __('super.features.subtitle') }}</p>
    </div>
    <a href="{{ route('super.cms.features.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> {{ __('super.features.add') }}</a>
</div>

{{-- Section config --}}
<div class="content-card mb-4">
    <div class="card-header-custom">
        <h2><i class="bi bi-sliders me-2 text-muted"></i>{{ __('super.features.section_header') }}</h2>
    </div>
    <div class="card-body-custom">
        <form method="POST" action="{{ route('super.cms.features.section.update') }}">
            @csrf
            {{-- section update uses POST --}}
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">{{ __('super.features.section_title') }}</label>
                    <input type="text" name="section_title" class="form-control" value="{{ old('section_title', $section->section_title) }}" placeholder="e.g. Everything you need to grow">
                </div>
                <div class="form-group">
                    <label class="form-label">{{ __('super.features.section_subtitle') }}</label>
                    <input type="text" name="section_subtitle" class="form-control" value="{{ old('section_subtitle', $section->section_subtitle) }}" placeholder="e.g. One platform for inventory, sales, and insights">
                </div>
            </div>
            <div class="form-group">
                <label class="form-switch-label">
                    <input type="checkbox" name="is_active" value="1" class="form-check-input" {{ $section->is_active ? 'checked' : '' }}>
                    <span>{{ __('super.features.show_section') }}</span>
                </label>
            </div>
            <button type="submit" class="btn btn-secondary btn-sm">{{ __('super.features.save_section') }}</button>
        </form>
    </div>
</div>

{{-- Feature items --}}
<div class="content-card">
    <div class="card-header-custom">
        <h2><i class="bi bi-grid-3x3-gap me-2 text-muted"></i>{{ __('super.features.items') }}</h2>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th class="w-50px">#</th>
                    <th>{{ __('super.features.field_title') }}</th>
                    <th>{{ __('super.features.icon_image') }}</th>
                    <th>{{ __('super.common.status') }}</th>
                    <th class="action-cell">{{ __('super.common.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($features as $f)
                <tr>
                    <td>{{ $f->sort_order }}</td>
                    <td class="fw-600">{{ $f->title }}</td>
                    <td>
                        @if($f->image)
                            <img src="{{ asset($f->image) }}" alt="" class="feature-thumb-sm">
                        @elseif($f->icon)
                            <i class="bi {{ $f->icon }} text-muted"></i> {{ $f->icon }}
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td><span class="badge badge-{{ $f->is_active ? 'yes' : 'no' }}">{{ $f->is_active ? __('super.common.active') : __('super.common.inactive') }}</span></td>
                    <td class="action-cell">
                        <a href="{{ route('super.cms.features.edit', $f) }}" class="btn btn-outline btn-sm">{{ __('super.common.edit') }}</a>
                        <form method="POST" action="{{ route('super.cms.features.destroy', $f) }}" class="d-inline swal-confirm-form" data-swal-confirm="{{ json_encode(['title' => __('super.common.delete') . '?', 'text' => __('super.features.delete_confirm'), 'icon' => 'warning', 'confirmButtonText' => __('super.common.delete'), 'confirmButtonColor' => '#ef4444']) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline btn-sm text-danger">{{ __('super.common.delete') }}</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="text-center py-4 text-muted">{{ __('super.features.no_features') }} <a href="{{ route('super.cms.features.create') }}">{{ __('super.features.add_one') }}</a></td>
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
