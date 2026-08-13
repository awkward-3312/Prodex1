@extends('central.super.layout')

@section('title', __('super.hero.title'))

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.cms.index') }}">CMS</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ __('super.hero.title') }}</span>
</div>

<div class="page-header">
    <h1>{{ __('super.hero.title') }}</h1>
    <p class="page-subtitle">{{ __('super.hero.subtitle') }}</p>
</div>

<div class="content-card">
    <div class="card-body-custom">
        <form method="POST" action="{{ route('super.cms.hero.update') }}" enctype="multipart/form-data">
            @csrf
            @method('PUT')

            <div class="form-group mb-4">
                <label class="form-label">{{ __('super.hero.field_title') }} <span class="text-danger">*</span></label>
                <input type="text" name="title" class="form-control" value="{{ old('title', $hero->title) }}" placeholder="e.g. Run your business smarter with Stocky" required>
            </div>

            <div class="form-group mb-4">
                <label class="form-label">{{ __('super.hero.badge_text') }}</label>
                <input type="text" name="subtitle" class="form-control" value="{{ old('subtitle', $hero->subtitle) }}" placeholder="e.g. Inventory & POS in one platform">
            </div>

            <div class="form-group mb-4">
                <label class="form-label">{{ __('super.hero.description') }}</label>
                <textarea name="description" class="form-control" rows="3" placeholder="Short description below the title">{{ old('description', $hero->description) }}</textarea>
            </div>

            <div class="form-row mb-4">
                <div class="form-group">
                    <label class="form-label">{{ __('super.hero.primary_btn') }}</label>
                    <input type="text" name="primary_button_text" class="form-control" value="{{ old('primary_button_text', $hero->primary_button_text) }}" placeholder="e.g. Start Free Trial">
                </div>
                <div class="form-group">
                    <label class="form-label">{{ __('super.hero.primary_url') }}</label>
                    <input type="text" name="primary_button_url" class="form-control" value="{{ old('primary_button_url', $hero->primary_button_url) }}" placeholder="{{ route('central.register') }}">
                </div>
            </div>

            <div class="form-row mb-4">
                <div class="form-group">
                    <label class="form-label">{{ __('super.hero.secondary_btn') }}</label>
                    <input type="text" name="secondary_button_text" class="form-control" value="{{ old('secondary_button_text', $hero->secondary_button_text) }}" placeholder="e.g. Sign Up">
                </div>
                <div class="form-group">
                    <label class="form-label">{{ __('super.hero.secondary_url') }}</label>
                    <input type="text" name="secondary_button_url" class="form-control" value="{{ old('secondary_button_url', $hero->secondary_button_url) }}" placeholder="{{ route('central.register') }}">
                </div>
            </div>

            {{-- Hero & background images, per language --}}
            <div class="form-group mb-2">
                <label class="form-label">{{ __('super.hero.images_per_language') }}</label>
                <p class="form-hint mb-2">{{ __('super.hero.images_per_language_hint') }}</p>
            </div>

            {{-- Language tabs --}}
            <ul class="nav nav-tabs mb-3" role="tablist">
                @foreach($languages as $lang)
                    <li class="nav-item" role="presentation">
                        <button class="nav-link {{ $loop->first ? 'active' : '' }}" type="button"
                                data-bs-toggle="tab" data-bs-target="#heroimg-{{ $lang->locale }}" role="tab">
                            @if($lang->flag)
                                <img src="{{ asset('flags/' . $lang->flag) }}" alt="" class="flag-icon-sm me-1">
                            @endif
                            {{ $lang->name }} ({{ strtoupper($lang->locale) }})
                            @if($lang->locale === $baseLocale)
                                <span class="badge bg-secondary ms-1">{{ __('super.hero.default_lang') }}</span>
                            @endif
                        </button>
                    </li>
                @endforeach
            </ul>

            <div class="tab-content mb-4">
                @foreach($languages as $lang)
                    @php
                        $loc = $lang->locale;
                        $isBase = $loc === $baseLocale;
                        $ownHero = $hero->ownTranslation('hero_image', $loc);
                        $ownBg   = $hero->ownTranslation('background_image', $loc);
                        $fallbackHero = ! $ownHero ? $hero->trans('hero_image', $loc) : null;
                        $fallbackBg   = ! $ownBg ? $hero->trans('background_image', $loc) : null;
                    @endphp
                    <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}" id="heroimg-{{ $loc }}" role="tabpanel">
                        <div class="form-row">
                            {{-- Hero image --}}
                            <div class="form-group">
                                <label class="form-label">{{ __('super.hero.hero_image') }}</label>
                                <input type="file" name="hero_image[{{ $loc }}]" class="form-control" accept="image/*">
                                @if($ownHero)
                                    <p class="form-hint mt-1">
                                        {{ __('super.common.current') }}: <a href="{{ asset($ownHero) }}" target="_blank">{{ __('super.common.view_image') }}</a>
                                    </p>
                                    <label class="form-switch-label mt-1">
                                        <input type="checkbox" name="remove_hero_image[{{ $loc }}]" value="1" class="form-check-input">
                                        <span class="text-danger">{{ __('super.common.remove_image') }}</span>
                                    </label>
                                @elseif($fallbackHero && ! $isBase)
                                    <p class="form-hint mt-1 text-muted"><i class="bi bi-info-circle me-1"></i>{{ __('super.hero.uses_default_image') }}</p>
                                @endif
                            </div>
                            {{-- Background image --}}
                            <div class="form-group">
                                <label class="form-label">{{ __('super.hero.bg_image') }}</label>
                                <input type="file" name="background_image[{{ $loc }}]" class="form-control" accept="image/*">
                                @if($ownBg)
                                    <p class="form-hint mt-1">
                                        {{ __('super.common.current') }}: <a href="{{ asset($ownBg) }}" target="_blank">{{ __('super.common.view_image') }}</a>
                                    </p>
                                    <label class="form-switch-label mt-1">
                                        <input type="checkbox" name="remove_background_image[{{ $loc }}]" value="1" class="form-check-input">
                                        <span class="text-danger">{{ __('super.common.remove_image') }}</span>
                                    </label>
                                @elseif($fallbackBg && ! $isBase)
                                    <p class="form-hint mt-1 text-muted"><i class="bi bi-info-circle me-1"></i>{{ __('super.hero.uses_default_image') }}</p>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="form-group mb-4">
                <label class="form-switch-label">
                    <input type="checkbox" name="is_active" value="1" class="form-check-input" {{ old('is_active', $hero->is_active) ? 'checked' : '' }}>
                    <span>{{ __('super.hero.show_section') }}</span>
                </label>
            </div>

            <div class="divider"></div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> {{ __('super.common.save') }}</button>
                <a href="{{ route('central.welcome') }}" target="_blank" class="btn btn-secondary"><i class="bi bi-box-arrow-up-right"></i> {{ __('super.common.preview') }}</a>
            </div>
        </form>
    </div>
</div>
@endsection
