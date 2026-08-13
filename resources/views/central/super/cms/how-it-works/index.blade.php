@extends('central.super.layout')

@section('title', __('super.how_it_works.title'))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.cms.index') }}">CMS</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ __('super.how_it_works.title') }}</span>
</div>

<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
        <h1>{{ __('super.how_it_works.title') }}</h1>
        <p class="page-subtitle">{{ __('super.how_it_works.subtitle') }}</p>
    </div>
    <a href="{{ route('super.cms.how-it-works.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> {{ __('super.how_it_works.add') }}</a>
</div>

{{-- Section config --}}
<div class="content-card mb-4">
    <div class="card-header-custom">
        <h2><i class="bi bi-sliders me-2 text-muted"></i>{{ __('super.how_it_works.section_header') }}</h2>
    </div>
    <div class="card-body-custom">
        <form method="POST" action="{{ route('super.cms.how-it-works.section.update') }}">
            @csrf
            <div class="form-group mb-3">
                <label class="form-label">{{ __('super.how_it_works.section_label') }}</label>
                <input type="text" name="section_label" class="form-control" value="{{ old('section_label', $section->section_label) }}" placeholder="e.g. How It Works">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">{{ __('super.how_it_works.section_title_field') }}</label>
                    <input type="text" name="section_title" class="form-control" value="{{ old('section_title', $section->section_title) }}" placeholder="e.g. Transparent & fair for every customer">
                </div>
                <div class="form-group">
                    <label class="form-label">{{ __('super.how_it_works.section_subtitle_field') }}</label>
                    <input type="text" name="section_subtitle" class="form-control" value="{{ old('section_subtitle', $section->section_subtitle) }}" placeholder="e.g. Here is how plans, pricing, and your data are handled">
                </div>
            </div>
            <div class="form-group">
                <label class="form-switch-label">
                    <input type="checkbox" name="is_active" value="1" class="form-check-input" {{ $section->is_active ? 'checked' : '' }}>
                    <span>{{ __('super.how_it_works.show_section') }}</span>
                </label>
            </div>
            <button type="submit" class="btn btn-secondary btn-sm">{{ __('super.how_it_works.save_section') }}</button>
        </form>
    </div>
</div>

{{-- Step items --}}
<div class="content-card">
    <div class="card-header-custom">
        <h2><i class="bi bi-diagram-3 me-2 text-muted"></i>{{ __('super.how_it_works.items') }}</h2>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th class="w-50px">#</th>
                    <th>{{ __('super.how_it_works.field_title') }}</th>
                    <th>{{ __('super.how_it_works.field_icon') }}</th>
                    <th>{{ __('super.how_it_works.field_color') }}</th>
                    <th>{{ __('super.common.status') }}</th>
                    <th class="action-cell">{{ __('super.common.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($steps as $step)
                <tr>
                    <td>{{ $step->sort_order }}</td>
                    <td class="fw-600">{{ $step->title }}</td>
                    <td>
                        @if($step->icon)
                            <i class="{{ $step->icon }} text-muted"></i> {{ $step->icon }}
                        @else
                            <span class="text-muted">&mdash;</span>
                        @endif
                    </td>
                    <td>{{ $step->icon_color ?? '—' }}</td>
                    <td><span class="badge badge-{{ $step->is_active ? 'yes' : 'no' }}">{{ $step->is_active ? __('super.common.active') : __('super.common.inactive') }}</span></td>
                    <td class="action-cell">
                        <a href="{{ route('super.cms.how-it-works.edit', $step) }}" class="btn btn-outline btn-sm">{{ __('super.common.edit') }}</a>
                        <form method="POST" action="{{ route('super.cms.how-it-works.destroy', $step) }}" class="d-inline swal-confirm-form" data-swal-confirm="{{ json_encode(['title' => __('super.common.delete') . '?', 'text' => __('super.how_it_works.delete_confirm'), 'icon' => 'warning', 'confirmButtonText' => __('super.common.delete'), 'confirmButtonColor' => '#ef4444']) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline btn-sm text-danger">{{ __('super.common.delete') }}</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="text-center py-4 text-muted">{{ __('super.how_it_works.no_steps') }} <a href="{{ route('super.cms.how-it-works.create') }}">{{ __('super.how_it_works.add_one') }}</a></td>
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
