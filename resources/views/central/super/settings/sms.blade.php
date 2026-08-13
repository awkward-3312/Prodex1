@extends('central.super.layout')

@section('title', __('central.SmsSettings'))

@section('content')
<div class="page-header">
    <h1>{{ __('central.SmsSettings') }}</h1>
    <p class="page-subtitle">{{ __('central.SmsSettingsSubtitle') }}</p>
</div>

<form method="POST" action="{{ route('super.settings.sms.update') }}">
    @csrf
    @method('PUT')

    <div class="row g-4">
        <div class="col-lg-8">
            {{-- Gateway --}}
            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-chat-left-text me-2 text-muted"></i>{{ __('central.SmsGateway') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group">
                        <label class="form-label">{{ __('central.Gateway') }}</label>
                        <select name="sms_gateway" class="form-control" id="gatewaySelect">
                            @foreach($gateways as $key => $label)
                                <option value="{{ $key }}" {{ old('sms_gateway', $setting->sms_gateway ?? '') === $key ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="form-hint">{{ __('central.SmsGatewayHint') }}</p>
                        @error('sms_gateway')
                            <div class="text-danger mt-1 fs-sm2">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>

            {{-- Twilio --}}
            <div class="content-card mb-4 gateway-section" id="gw-twilio">
                <div class="card-header-custom">
                    <h2><i class="bi bi-telephone me-2 text-muted"></i>{{ __('central.TwilioCredentials') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('central.AccountSid') }}</label>
                        <input
                            type="text"
                            name="twilio_sid"
                            class="form-control @error('twilio_sid') is-invalid @enderror"
                            value="{{ old('twilio_sid', $setting->twilio_sid) }}"
                            placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                            autocomplete="off"
                        >
                        @error('twilio_sid')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="form-group mb-4">
                        <label class="form-label">
                            {{ __('central.AuthToken') }}
                            <i class="bi bi-lock-fill text-muted fs-xs" title="{{ __('central.StoredEncrypted') }}"></i>
                        </label>
                        <div class="position-relative">
                            <input
                                type="password"
                                name="twilio_token"
                                class="form-control secret-field @error('twilio_token') is-invalid @enderror"
                                value="{{ $setting->getDecryptedSecret('twilio_token') ? '••••••••' : '' }}"
                                autocomplete="new-password"
                            >
                            <button type="button" class="btn-toggle-secret" title="{{ __('central.ToggleVisibility') }}" tabindex="-1">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        @if($setting->getDecryptedSecret('twilio_token'))
                            <p class="form-hint">{{ __('central.LeaveBlankToKeepSecret') }}</p>
                        @endif
                        @error('twilio_token')
                            <div class="text-danger mt-1 fs-sm2">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label">{{ __('central.FromNumber') }}</label>
                        <input
                            type="text"
                            name="twilio_from"
                            class="form-control @error('twilio_from') is-invalid @enderror"
                            value="{{ old('twilio_from', $setting->twilio_from) }}"
                            placeholder="+14155552671"
                            autocomplete="off"
                        >
                        @error('twilio_from')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>

            {{-- Infobip --}}
            <div class="content-card mb-4 gateway-section" id="gw-infobip">
                <div class="card-header-custom">
                    <h2><i class="bi bi-broadcast me-2 text-muted"></i>{{ __('central.InfobipCredentials') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('central.BaseUrl') }}</label>
                        <input
                            type="text"
                            name="infobip_base_url"
                            class="form-control @error('infobip_base_url') is-invalid @enderror"
                            value="{{ old('infobip_base_url', $setting->infobip_base_url) }}"
                            placeholder="https://xxxxx.api.infobip.com"
                            autocomplete="off"
                        >
                        @error('infobip_base_url')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="form-group mb-4">
                        <label class="form-label">
                            {{ __('central.ApiKey') }}
                            <i class="bi bi-lock-fill text-muted fs-xs" title="{{ __('central.StoredEncrypted') }}"></i>
                        </label>
                        <div class="position-relative">
                            <input
                                type="password"
                                name="infobip_api_key"
                                class="form-control secret-field @error('infobip_api_key') is-invalid @enderror"
                                value="{{ $setting->getDecryptedSecret('infobip_api_key') ? '••••••••' : '' }}"
                                autocomplete="new-password"
                            >
                            <button type="button" class="btn-toggle-secret" title="{{ __('central.ToggleVisibility') }}" tabindex="-1">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        @if($setting->getDecryptedSecret('infobip_api_key'))
                            <p class="form-hint">{{ __('central.LeaveBlankToKeepSecret') }}</p>
                        @endif
                        @error('infobip_api_key')
                            <div class="text-danger mt-1 fs-sm2">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label">{{ __('central.SenderFrom') }}</label>
                        <input
                            type="text"
                            name="infobip_sender_from"
                            class="form-control @error('infobip_sender_from') is-invalid @enderror"
                            value="{{ old('infobip_sender_from', $setting->infobip_sender_from) }}"
                            placeholder="InfoSMS"
                            autocomplete="off"
                        >
                        @error('infobip_sender_from')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>

            {{-- Termii --}}
            <div class="content-card mb-4 gateway-section" id="gw-termii">
                <div class="card-header-custom">
                    <h2><i class="bi bi-chat-dots me-2 text-muted"></i>{{ __('central.TermiiCredentials') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group mb-4">
                        <label class="form-label">
                            {{ __('central.ApiKey') }}
                            <i class="bi bi-lock-fill text-muted fs-xs" title="{{ __('central.StoredEncrypted') }}"></i>
                        </label>
                        <div class="position-relative">
                            <input
                                type="password"
                                name="termii_api_key"
                                class="form-control secret-field @error('termii_api_key') is-invalid @enderror"
                                value="{{ $setting->getDecryptedSecret('termii_api_key') ? '••••••••' : '' }}"
                                autocomplete="new-password"
                            >
                            <button type="button" class="btn-toggle-secret" title="{{ __('central.ToggleVisibility') }}" tabindex="-1">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        @if($setting->getDecryptedSecret('termii_api_key'))
                            <p class="form-hint">{{ __('central.LeaveBlankToKeepSecret') }}</p>
                        @endif
                        @error('termii_api_key')
                            <div class="text-danger mt-1 fs-sm2">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="form-group mb-4">
                        <label class="form-label">
                            {{ __('central.ApiSecret') }}
                            <i class="bi bi-lock-fill text-muted fs-xs" title="{{ __('central.StoredEncrypted') }}"></i>
                        </label>
                        <div class="position-relative">
                            <input
                                type="password"
                                name="termii_secret"
                                class="form-control secret-field @error('termii_secret') is-invalid @enderror"
                                value="{{ $setting->getDecryptedSecret('termii_secret') ? '••••••••' : '' }}"
                                autocomplete="new-password"
                            >
                            <button type="button" class="btn-toggle-secret" title="{{ __('central.ToggleVisibility') }}" tabindex="-1">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        @if($setting->getDecryptedSecret('termii_secret'))
                            <p class="form-hint">{{ __('central.LeaveBlankToKeepSecret') }}</p>
                        @endif
                        @error('termii_secret')
                            <div class="text-danger mt-1 fs-sm2">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label">{{ __('central.SenderId') }}</label>
                        <input
                            type="text"
                            name="termii_sender"
                            class="form-control @error('termii_sender') is-invalid @enderror"
                            value="{{ old('termii_sender', $setting->termii_sender) }}"
                            placeholder="Stocky"
                            autocomplete="off"
                        >
                        @error('termii_sender')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>

            {{-- Custom HTTP gateway --}}
            <div class="content-card mb-4 gateway-section" id="gw-custom">
                <div class="card-header-custom">
                    <h2><i class="bi bi-code-slash me-2 text-muted"></i>{{ __('central.CustomGatewayTitle') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('central.ApiUrl') }}</label>
                        <input
                            type="text"
                            name="custom_api_url"
                            class="form-control @error('custom_api_url') is-invalid @enderror"
                            value="{{ old('custom_api_url', $setting->custom_api_url) }}"
                            placeholder="https://sms-provider.com/api/send"
                            autocomplete="off"
                        >
                        @error('custom_api_url')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('central.HttpMethod') }}</label>
                                <select name="custom_method" class="form-control">
                                    @foreach(['POST', 'GET', 'PUT'] as $method)
                                        <option value="{{ $method }}" {{ old('custom_method', $setting->custom_method ?: 'POST') === $method ? 'selected' : '' }}>{{ $method }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('central.ContentType') }}</label>
                                <select name="custom_content_type" class="form-control">
                                    <option value="json" {{ old('custom_content_type', $setting->custom_content_type ?: 'json') === 'json' ? 'selected' : '' }}>JSON</option>
                                    <option value="form" {{ old('custom_content_type', $setting->custom_content_type ?: 'json') === 'form' ? 'selected' : '' }}>Form</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="form-label">{{ __('central.SenderId') }}</label>
                                <input
                                    type="text"
                                    name="custom_sender"
                                    class="form-control @error('custom_sender') is-invalid @enderror"
                                    value="{{ old('custom_sender', $setting->custom_sender) }}"
                                    autocomplete="off"
                                >
                                @error('custom_sender')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('central.HeadersJson') }}</label>
                        <textarea
                            name="custom_headers"
                            class="form-control font-monospace @error('custom_headers') is-invalid @enderror"
                            rows="3"
                            placeholder='{"Authorization": "Bearer YOUR_KEY"}'
                        >{{ old('custom_headers', $setting->custom_headers ? json_encode($setting->custom_headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '') }}</textarea>
                        @error('custom_headers')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('central.PayloadJson') }}</label>
                        <textarea
                            name="custom_payload"
                            class="form-control font-monospace @error('custom_payload') is-invalid @enderror"
                            rows="4"
                            placeholder='{"to": "{phone}", "message": "{message}", "sender_id": "{sender}"}'
                        >{{ old('custom_payload', $setting->custom_payload ? json_encode($setting->custom_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '') }}</textarea>
                        <p class="form-hint">{{ __('central.CustomPlaceholdersHint') }}</p>
                        @error('custom_payload')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="form-group">
                        <label class="form-label">{{ __('central.SuccessKeyword') }}</label>
                        <input
                            type="text"
                            name="custom_success_keyword"
                            class="form-control @error('custom_success_keyword') is-invalid @enderror"
                            value="{{ old('custom_success_keyword', $setting->custom_success_keyword) }}"
                            placeholder="success"
                            autocomplete="off"
                        >
                        <p class="form-hint">{{ __('central.SuccessKeywordHint') }}</p>
                        @error('custom_success_keyword')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>

            {{-- Save --}}
            <div class="content-card mb-4">
                <div class="card-body-custom">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> {{ __('central.SaveSmsSettings') }}
                    </button>
                </div>
            </div>
        </div>

        {{-- Sidebar --}}
        <div class="col-lg-4">
            <div class="content-card mb-4 sticky-sidebar">
                <div class="card-header-custom">
                    <h2><i class="bi bi-send me-2 text-muted"></i>{{ __('central.SendTestSms') }}</h2>
                </div>
                <div class="card-body-custom">
                    <p class="text-muted mb-3 fs-sm2">{{ __('central.VerifySmsGatewayDesc') }}</p>
                </div>
            </div>

            {{-- Quick reference --}}
            <div class="content-card">
                <div class="card-header-custom">
                    <h2><i class="bi bi-info-circle me-2 text-muted"></i>{{ __('central.SmsGatewayNotes') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="d-flex flex-column gap-3 fs-sm2">
                        <div class="smtp-ref">
                            <p class="fw-700 mb-1">Twilio</p>
                            <p class="text-muted mb-0">{{ __('central.TwilioNote') }}</p>
                        </div>
                        <div class="smtp-ref">
                            <p class="fw-700 mb-1">Infobip</p>
                            <p class="text-muted mb-0">{{ __('central.InfobipNote') }}</p>
                        </div>
                        <div class="smtp-ref">
                            <p class="fw-700 mb-1">Termii</p>
                            <p class="text-muted mb-0">{{ __('central.TermiiNote') }}</p>
                        </div>
                        <div class="smtp-ref">
                            <p class="fw-700 mb-1">{{ __('central.CustomGatewayTitle') }}</p>
                            <p class="text-muted mb-0">{{ __('central.CustomGatewayNote') }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

{{-- Test SMS form (outside main form) --}}
<div class="row">
    <div class="col-lg-4 offset-lg-8 mail-test-offset">
        <div class="content-card">
            <div class="card-body-custom">
                <form method="POST" action="{{ route('super.settings.sms.test') }}">
                    @csrf
                    <div class="form-group mb-2">
                        <input
                            type="text"
                            name="test_phone"
                            class="form-control @error('test_phone') is-invalid @enderror"
                            value="{{ old('test_phone') }}"
                            placeholder="+14155552671"
                        >
                        <p class="form-hint">{{ __('central.TestPhoneHint') }}</p>
                        @error('test_phone')
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
<script src="{{ asset('assets_super/js/settings-sms.js') }}"></script>
@endpush

@endsection
