@extends('central.super.layout')

@section('title', __('super.testimonials.edit_title'))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.cms.index') }}">CMS</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <a href="{{ route('super.cms.testimonials') }}">{{ __('super.testimonials.title') }}</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ $testimonial->client_name }}</span>
</div>

<div class="page-header">
    <h1>{{ __('super.testimonials.edit_title') }}</h1>
    <p class="page-subtitle">{{ $testimonial->client_name }}{{ $testimonial->company_name ? ' — ' . $testimonial->company_name : '' }}</p>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="content-card">
            <div class="card-body-custom">
                <form method="POST" action="{{ route('super.cms.testimonials.update', $testimonial) }}" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')
                    <div class="form-row mb-4">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.testimonials.client_name') }} <span class="text-danger">*</span></label>
                            <input type="text" name="client_name" class="form-control" value="{{ old('client_name', $testimonial->client_name) }}" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">{{ __('super.testimonials.company_name') }}</label>
                            <input type="text" name="company_name" class="form-control" value="{{ old('company_name', $testimonial->company_name) }}">
                        </div>
                    </div>
                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.testimonials.review') }} <span class="text-danger">*</span></label>
                        <textarea name="review" class="form-control" rows="4" required>{{ old('review', $testimonial->review) }}</textarea>
                    </div>
                    <div class="form-row mb-4">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.testimonials.rating') }}</label>
                            <select name="rating" class="form-control">
                                <option value="">{{ __('super.testimonials.no_rating') }}</option>
                                @for($i = 5; $i >= 1; $i--)
                                    <option value="{{ $i }}" {{ old('rating', $testimonial->rating) == $i ? 'selected' : '' }}>{{ $i }} {{ $i == 1 ? __('super.testimonials.star') : __('super.testimonials.stars') }}</option>
                                @endfor
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">{{ __('super.testimonials.avatar') }}</label>
                            <input type="file" name="avatar" class="form-control" accept="image/*">
                            @if($testimonial->avatar)
                                <p class="form-hint mt-1">{{ __('super.common.current') }}: <a href="{{ asset($testimonial->avatar) }}" target="_blank">{{ __('super.common.view_image') }}</a></p>
                            @endif
                        </div>
                    </div>
                    <div class="form-row mb-4">
                        <div class="form-group">
                            <label class="form-label">{{ __('super.common.sort_order') }}</label>
                            <input type="number" name="sort_order" class="form-control" value="{{ old('sort_order', $testimonial->sort_order) }}" min="0">
                        </div>
                        <div class="form-group d-flex align-items-end">
                            <label class="form-switch-label">
                                <input type="checkbox" name="is_active" value="1" class="form-check-input" {{ old('is_active', $testimonial->is_active) ? 'checked' : '' }}>
                                <span>{{ __('super.common.active') }}</span>
                            </label>
                        </div>
                    </div>
                    <div class="divider"></div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> {{ __('super.common.update') }}</button>
                        <a href="{{ route('super.cms.testimonials') }}" class="btn btn-secondary">{{ __('super.common.cancel') }}</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
