@extends('central.super.layout')

@section('title', __('super.profile.title'))

@section('content')
<div class="page-header">
    <h1>{{ __('super.profile.title') }}</h1>
    <p class="page-subtitle">{{ __('super.profile.subtitle') }}</p>
</div>

<div class="row g-4">
    {{-- Left column --}}
    <div class="col-lg-4">
        {{-- Profile card --}}
        <div class="content-card text-center mb-4">
            <div class="card-body-custom profile-card-body">
                <div class="position-relative mx-auto mb-3 profile-avatar-wrap">
                    @if($user->avatar)
                        <img src="{{ asset($user->avatar) }}" alt="{{ $user->name }}" class="profile-avatar-img">
                    @else
                        <div class="profile-avatar-fallback">{{ strtoupper(mb_substr($user->name, 0, 1)) }}</div>
                    @endif
                    <label for="avatarInput" class="avatar-upload-btn" title="Change avatar">
                        <i class="bi bi-camera-fill"></i>
                    </label>
                </div>
                <form method="POST" action="{{ route('super.profile.avatar') }}" enctype="multipart/form-data" id="avatarForm">
                    @csrf
                    <input type="file" name="avatar" id="avatarInput" accept="image/jpeg,image/png,image/webp" class="d-none">
                </form>
                @error('avatar')
                    <div class="text-danger mb-2 fs-sm">{{ $message }}</div>
                @enderror
                <h2 class="fw-800 mb-1 fs-lg3">{{ $user->name }}</h2>
                <p class="text-muted mb-3 fs-sm3">{{ $user->email }}</p>
                <span class="d-inline-flex align-items-center gap-1 px-3 py-1 profile-badge-admin">
                    <i class="bi bi-shield-check"></i> {{ __('super.profile.super_admin') }}
                </span>
            </div>
        </div>

        {{-- Account info --}}
        <div class="content-card">
            <div class="card-header-custom">
                <h2><i class="bi bi-info-circle me-2 text-muted"></i>{{ __('super.profile.account_info') }}</h2>
            </div>
            <div class="card-body-custom">
                <div class="d-flex flex-column gap-3 profile-info-list">
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">{{ __('super.profile.member_since') }}</span>
                        <span class="fw-600">{{ $user->created_at->format('M d, Y') }}</span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">{{ __('super.profile.last_updated') }}</span>
                        <span class="fw-600">{{ $user->updated_at->diffForHumans() }}</span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">{{ __('super.profile.user_id') }}</span>
                        <span class="fw-600">#{{ $user->id }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Right column --}}
    <div class="col-lg-8">
        {{-- Update profile --}}
        <div class="content-card mb-4">
            <div class="card-header-custom">
                <h2><i class="bi bi-person me-2 text-muted"></i>{{ __('super.profile.profile_info') }}</h2>
            </div>
            <div class="card-body-custom">
                <form method="POST" action="{{ route('super.profile.update') }}">
                    @csrf
                    @method('PUT')

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">{{ __('super.profile.full_name') }}</label>
                            <input type="text" name="name" value="{{ old('name', $user->name) }}" class="form-control @error('name') is-invalid @enderror" required>
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">{{ __('super.profile.email_address') }}</label>
                            <input type="email" name="email" value="{{ old('email', $user->email) }}" class="form-control @error('email') is-invalid @enderror" required>
                            @error('email')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> {{ __('super.common.save_changes') }}
                    </button>
                </form>
            </div>
        </div>

        {{-- Change password --}}
        <div class="content-card">
            <div class="card-header-custom">
                <h2><i class="bi bi-shield-lock me-2 text-muted"></i>{{ __('super.profile.change_password') }}</h2>
            </div>
            <div class="card-body-custom">
                <form method="POST" action="{{ route('super.profile.password') }}">
                    @csrf
                    @method('PUT')

                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <label class="form-label">{{ __('super.profile.current_password') }}</label>
                            <div class="position-relative">
                                <input type="password" name="current_password" class="form-control pe-5 @error('current_password') is-invalid @enderror" required autocomplete="current-password">
                                <button type="button" class="btn-pw-toggle" tabindex="-1"><i class="bi bi-eye"></i></button>
                            </div>
                            @error('current_password')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">{{ __('super.profile.new_password') }}</label>
                            <div class="position-relative">
                                <input type="password" name="password" class="form-control pe-5 @error('password') is-invalid @enderror" required minlength="8" autocomplete="new-password" id="newPasswordInput">
                                <button type="button" class="btn-pw-toggle" tabindex="-1"><i class="bi bi-eye"></i></button>
                            </div>
                            @error('password')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                            <div class="password-strength mt-2" id="passwordStrength">
                                <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                                <span class="strength-text" id="strengthText"></span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">{{ __('super.profile.confirm_password') }}</label>
                            <div class="position-relative">
                                <input type="password" name="password_confirmation" class="form-control pe-5" required minlength="8" autocomplete="new-password">
                                <button type="button" class="btn-pw-toggle" tabindex="-1"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex align-items-center gap-3 flex-wrap">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-lock"></i> {{ __('super.profile.update_password') }}
                        </button>
                        <span class="text-muted fs-sm">
                            <i class="bi bi-info-circle"></i> {{ __('super.profile.password_hint') }}
                        </span>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
window.ProfileData = {
    strengthVeryWeak:   @json(__('super.profile.strength_very_weak')),
    strengthWeak:       @json(__('super.profile.strength_weak')),
    strengthFair:       @json(__('super.profile.strength_fair')),
    strengthStrong:     @json(__('super.profile.strength_strong')),
    strengthVeryStrong: @json(__('super.profile.strength_very_strong')),
};
</script>
<script src="{{ asset('assets_super/js/profile-show.js') }}"></script>
@endpush
@endsection
