@extends('central.super.layout')

@section('title', __('central.MailSettings'))

@section('content')
<div class="page-header">
    <h1>{{ __('central.MailSettings') }}</h1>
    <p class="page-subtitle">{{ __('central.MailSettingsSubtitle') }}</p>
</div>

<form method="POST" action="{{ route('super.settings.mail.update') }}">
    @csrf
    @method('PUT')

    <div class="row g-4">
        <div class="col-lg-8">
            {{-- Mailer & Connection --}}
            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-envelope me-2 text-muted"></i>{{ __('central.MailDriver') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('central.Mailer') }}</label>
                        <select name="mail_mailer" class="form-control" id="mailerSelect">
                            @foreach($mailers as $key => $label)
                                <option value="{{ $key }}" {{ old('mail_mailer', $setting->mail_mailer) === $key ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('mail_mailer')
                            <div class="text-danger mt-1 fs-sm2">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>

            {{-- SMTP Settings --}}
            <div class="content-card mb-4" id="smtpSettings">
                <div class="card-header-custom">
                    <h2><i class="bi bi-hdd-network me-2 text-muted"></i>{{ __('central.SmtpServer') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="row g-3 mb-4">
                        <div class="col-md-8">
                            <div class="form-group">
                                <label class="form-label">{{ __('central.SmtpHost') }}</label>
                                <input
                                    type="text"
                                    name="mail_host"
                                    class="form-control @error('mail_host') is-invalid @enderror"
                                    value="{{ old('mail_host', $setting->mail_host) }}"
                                    placeholder="e.g. smtp.gmail.com"
                                >
                                @error('mail_host')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('central.Port') }}</label>
                                <input
                                    type="number"
                                    name="mail_port"
                                    class="form-control @error('mail_port') is-invalid @enderror"
                                    value="{{ old('mail_port', $setting->mail_port) }}"
                                    placeholder="587"
                                    min="1"
                                    max="65535"
                                >
                                @error('mail_port')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('central.Encryption') }}</label>
                        <div class="d-flex gap-3">
                            @foreach($encryptions as $key => $label)
                            <label class="encryption-option {{ old('mail_encryption', $setting->mail_encryption ?? '') === $key ? 'selected' : '' }}">
                                <input type="radio" name="mail_encryption" value="{{ $key }}" class="d-none encryption-radio" {{ old('mail_encryption', $setting->mail_encryption ?? '') === $key ? 'checked' : '' }}>
                                <div class="encryption-card">
                                    <span class="fw-600 fs-sm4">{{ $label }}</span>
                                </div>
                            </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('central.Username') }}</label>
                        <input
                            type="text"
                            name="mail_username"
                            class="form-control @error('mail_username') is-invalid @enderror"
                            value="{{ old('mail_username', $setting->mail_username) }}"
                            placeholder="your-email@gmail.com"
                            autocomplete="off"
                        >
                        @error('mail_username')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            {{ __('central.Password') }}
                            <i class="bi bi-lock-fill text-muted fs-xs" title="{{ __('central.StoredEncrypted') }}"></i>
                        </label>
                        <div class="position-relative">
                            <input
                                type="password"
                                name="mail_password"
                                class="form-control secret-field @error('mail_password') is-invalid @enderror"
                                value="{{ $setting->getDecryptedPassword() ? '••••••••' : '' }}"
                                placeholder="{{ __('central.SmtpPasswordPlaceholder') }}"
                                autocomplete="new-password"
                            >
                            <button type="button" class="btn-toggle-secret" title="{{ __('central.ToggleVisibility') }}" tabindex="-1">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        @if($setting->getDecryptedPassword())
                            <p class="form-hint">{{ __('central.LeaveBlankToKeepPassword') }}</p>
                        @endif
                        @error('mail_password')
                            <div class="text-danger mt-1 fs-sm2">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>

            {{-- From Address --}}
            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-person-badge me-2 text-muted"></i>{{ __('central.SenderIdentity') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('central.FromEmailAddress') }}</label>
                                <input
                                    type="email"
                                    name="mail_from_address"
                                    class="form-control @error('mail_from_address') is-invalid @enderror"
                                    value="{{ old('mail_from_address', $setting->mail_from_address) }}"
                                    placeholder="noreply@yourdomain.com"
                                >
                                @error('mail_from_address')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('central.FromName') }}</label>
                                <input
                                    type="text"
                                    name="mail_from_name"
                                    class="form-control @error('mail_from_name') is-invalid @enderror"
                                    value="{{ old('mail_from_name', $setting->mail_from_name) }}"
                                    placeholder="Stocky"
                                >
                                @error('mail_from_name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Save --}}
            <div class="content-card mb-4">
                <div class="card-body-custom">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> {{ __('central.SaveMailSettings') }}
                    </button>
                </div>
            </div>
        </div>

        {{-- Sidebar --}}
        <div class="col-lg-4">
            {{-- Test email --}}
            <div class="content-card mb-4 sticky-sidebar">
                <div class="card-header-custom">
                    <h2><i class="bi bi-send me-2 text-muted"></i>{{ __('central.SendTestEmail') }}</h2>
                </div>
                <div class="card-body-custom">
                    <p class="text-muted mb-3 fs-sm2">{{ __('central.VerifySmtpDesc') }}</p>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="card-body-custom">
                    {{-- Separate form to avoid conflict with the main form --}}
                </div>
            </div>

            {{-- Quick reference --}}
            <div class="content-card">
                <div class="card-header-custom">
                    <h2><i class="bi bi-info-circle me-2 text-muted"></i>{{ __('central.CommonSmtpServers') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="d-flex flex-column gap-3 fs-sm2">
                        <div class="smtp-ref">
                            <p class="fw-700 mb-1">Gmail</p>
                            <p class="text-muted mb-0">Host: <code>smtp.gmail.com</code><br>Port: <code>587</code> (TLS) / <code>465</code> (SSL)<br>{{ __('central.GmailAppPasswordHint') }}</p>
                        </div>
                        <div class="smtp-ref">
                            <p class="fw-700 mb-1">Outlook / Office 365</p>
                            <p class="text-muted mb-0">Host: <code>smtp.office365.com</code><br>Port: <code>587</code> (TLS)</p>
                        </div>
                        <div class="smtp-ref">
                            <p class="fw-700 mb-1">Mailgun</p>
                            <p class="text-muted mb-0">Host: <code>smtp.mailgun.org</code><br>Port: <code>587</code> (TLS)</p>
                        </div>
                        <div class="smtp-ref">
                            <p class="fw-700 mb-1">SendGrid</p>
                            <p class="text-muted mb-0">Host: <code>smtp.sendgrid.net</code><br>Port: <code>587</code> (TLS)<br>{{ __('central.SendGridUsernameHint') }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

{{-- Test email form (outside main form) --}}
<div class="row">
    <div class="col-lg-4 offset-lg-8 mail-test-offset">
        <div class="content-card">
            <div class="card-body-custom">
                <form method="POST" action="{{ route('super.settings.mail.test') }}">
                    @csrf
                    <div class="form-group mb-2">
                        <input
                            type="email"
                            name="test_email"
                            class="form-control @error('test_email') is-invalid @enderror"
                            value="{{ old('test_email') }}"
                            placeholder="recipient@example.com"
                        >
                        @error('test_email')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <button type="submit" class="btn btn-outline btn-sm w-100">
                        <i class="bi bi-send"></i> {{ __('central.SendTest') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script src="{{ asset('assets_super/js/settings-mail.js') }}"></script>
@endpush

@endsection
