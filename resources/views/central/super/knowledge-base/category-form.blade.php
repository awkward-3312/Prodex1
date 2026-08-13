@extends('central.super.layout')

@section('title', $category ? __('super.kb.edit_category_title', ['name' => $category->name]) : __('super.kb.new_category_title'))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.knowledge-base.index') }}">{{ __('super.kb.title') }}</a>
    <span class="separator">/</span>
    <span class="current">{{ $category ? __('super.kb.edit_category') : __('super.kb.new_category_title') }}</span>
</div>

<div class="page-header">
    <h1>{{ $category ? __('super.kb.edit_category') : __('super.kb.new_category_title') }}</h1>
    <p class="page-subtitle">{{ $category ? __('super.kb.update_category_desc') : __('super.kb.new_category_desc') }}</p>
</div>

<form method="POST" action="{{ $category ? route('super.knowledge-base.categories.update', $category) : route('super.knowledge-base.categories.store') }}">
    @csrf
    @if($category) @method('PUT') @endif

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="content-card">
                <div class="card-header-custom">
                    <h2><i class="bi bi-folder2 me-2 text-muted"></i>{{ __('super.kb.category_details') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('super.kb.name') }}</label>
                                <input type="text" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $category->name ?? '') }}" required placeholder="e.g. Getting Started">
                                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('super.kb.slug') }}</label>
                                <input type="text" name="slug" class="form-control @error('slug') is-invalid @enderror" value="{{ old('slug', $category->slug ?? '') }}" required placeholder="getting-started">
                                @error('slug') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>

                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.kb.description') }} <span class="text-muted fw-normal">({{ __('super.kb.optional') }})</span></label>
                        <textarea name="description" class="form-control @error('description') is-invalid @enderror" rows="3" placeholder="{{ __('super.kb.description_placeholder') }}">{{ old('description', $category->description ?? '') }}</textarea>
                        @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('super.kb.icon') }} <span class="text-muted fw-normal">({{ __('super.kb.icon_class') }})</span></label>
                                <input type="text" name="icon" class="form-control @error('icon') is-invalid @enderror" value="{{ old('icon', $category->icon ?? '') }}" placeholder="e.g. book, gear, cart">
                                <small class="text-muted">{{ __('super.kb.icon_hint') }}</small>
                                @error('icon') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('super.kb.sort_order') }}</label>
                                <input type="number" name="sort_order" class="form-control max-w-120 @error('sort_order') is-invalid @enderror" value="{{ old('sort_order', $category->sort_order ?? 0) }}" min="0">
                                @error('sort_order') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>

                    <div class="d-flex align-items-center gap-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i> {{ $category ? __('super.kb.save_changes') : __('super.kb.create_category') }}
                        </button>
                        <a href="{{ route('super.knowledge-base.index') }}" class="back-link">{{ __('super.kb.cancel') }}</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="content-card sticky-sidebar">
                <div class="card-header-custom">
                    <h2><i class="bi bi-info-circle me-2 text-muted"></i>{{ __('super.kb.tips') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="d-flex flex-column gap-3 sidebar-tips-list">
                        <div class="d-flex gap-2">
                            <i class="bi bi-folder2 flex-shrink-0 tip-icon-primary"></i>
                            <span>{{ __('super.kb.tip_categories') }}</span>
                        </div>
                        <div class="d-flex gap-2">
                            <i class="bi bi-link-45deg flex-shrink-0 tip-icon-info"></i>
                            <span>{!! __('super.kb.tip_slug') !!}</span>
                        </div>
                        <div class="d-flex gap-2">
                            <i class="bi bi-sort-numeric-down flex-shrink-0 tip-icon-success"></i>
                            <span>{{ __('super.kb.tip_sort') }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

@push('scripts')
<script>
window.KbCategoryData = { isNew: {{ $category ? 'false' : 'true' }} };
</script>
<script src="{{ asset('assets_super/js/kb-category-form.js') }}"></script>
@endpush
@endsection
