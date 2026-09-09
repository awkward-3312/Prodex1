<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('central.Checkout') }} — {{ $plan->name }} {{ __('central.PlanLabel') }}</title>
    <link rel="icon" href="{{ asset('images/super/settings/favicon.ico') }}">
    <link href="{{ asset('assets_super/css/plus-jakarta-sans.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/central-shared.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/checkout.css') }}" rel="stylesheet">
</head>
<body>
    <div class="checkout-container">
        <div class="mb-4">
            <a href="{{ route('central.register') }}" class="back-link">
                <i class="bi bi-arrow-left"></i> {{ __('central.BackToRegistration') }}
            </a>
        </div>

        @if(request('cancelled'))
        <div class="alert alert-warning d-flex align-items-center gap-2 mb-4 checkout-alert">
            <i class="bi bi-exclamation-triangle-fill"></i>
            {{ __('central.PaymentCancelled') }}
        </div>
        @endif

        @if($errors->any())
        <div class="alert alert-danger d-flex align-items-center gap-2 mb-4 checkout-alert">
            <i class="bi bi-x-circle-fill"></i>
            {{ $errors->first() }}
        </div>
        @endif

        <div class="row g-4">
            <div class="col-lg-5 order-lg-2">
                <div class="checkout-card sticky-sidebar-sm">
                    <div class="checkout-card-header">
                        <i class="bi bi-receipt me-2 text-muted"></i>{{ __('central.OrderSummary') }}
                    </div>
                    <div class="checkout-card-body">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">{{ __('central.Plan') }}</span>
                            <span class="fw-bold">{{ $plan->name }}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">{{ __('central.BillingCycle') }}</span>
                            <span class="fw-bold">{{ ucfirst($cycle) }}</span>
                        </div>
                        <hr class="checkout-separator">
                        <div class="d-flex justify-content-between">
                            <span class="checkout-total-label">{{ __('central.Total') }}</span>
                            <span class="checkout-total-value">{{ $currencySymbol }}{{ number_format($amount, 2) }} {{ $currencyCode }}</span>
                        </div>

                        <div class="mt-3 pt-3 border-top-border-light">
                            <p class="checkout-section-title">{{ __('central.Included') }}</p>
                            @foreach($plan->getFormattedLimits() as $limit)
                            <div class="d-flex align-items-center gap-2 mb-1 checkout-list-item">
                                <i class="bi bi-check text-success"></i>
                                <span>{{ $limit['display'] }} {{ $limit['label'] }}</span>
                            </div>
                            @endforeach
                            @foreach($plan->getActiveFeatures() as $feature)
                            <div class="d-flex align-items-center gap-2 mb-1 checkout-list-item">
                                <i class="bi bi-check text-success"></i>
                                <span>{{ $feature['label'] }}</span>
                            </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-7 order-lg-1">
                <form method="POST" action="{{ route('central.checkout.process', ['token' => $registration->token]) }}" id="checkoutForm" enctype="multipart/form-data">
                    @csrf

                    <div class="checkout-card mb-4">
                        <div class="checkout-card-header">
                            <i class="bi bi-credit-card me-2 text-muted"></i>{{ __('central.PaymentMethod') }}
                        </div>
                        <div class="checkout-card-body">
                            @if(count($gateways) === 0)
                                <div class="text-center py-4">
                                    <i class="bi bi-exclamation-triangle no-gateways-icon"></i>
                                    <p class="text-muted mt-2">{{ __('central.NoGatewaysConfigured') }}</p>
                                </div>
                            @else
                                <div class="d-flex flex-column gap-2">
                                    @foreach($gateways as $i => $gw)
                                    <label class="gateway-option {{ $i === 0 ? 'selected' : '' }}">
                                        <input type="radio" name="gateway" value="{{ $gw['key'] }}" {{ $i === 0 ? 'checked' : '' }} class="d-none gateway-radio">
                                        <div class="gateway-card">
                                            <div class="d-flex align-items-center gap-3">
                                                <div class="gateway-icon-box" style="background: {{ $gw['color'] }}15;">
                                                    <i class="bi {{ $gw['icon'] }} gateway-icon" style="color: {{ $gw['color'] }};"></i>
                                                </div>
                                                <div>
                                                    <p class="mb-0 fw-bold gateway-label">{{ $gw['label'] }}</p>
                                                    <p class="mb-0 text-muted gateway-desc">
                                                        @if($gw['key'] === 'offline')
                                                            {{ __('central.PayViaBankTransfer') }}
                                                        @else
                                                            {{ __('central.PaySecurelyWith') }} {{ $gw['label'] }}
                                                        @endif
                                                    </p>
                                                </div>
                                            </div>
                                            <div class="gateway-check">
                                                <i class="bi bi-check-circle-fill"></i>
                                            </div>
                                        </div>
                                    </label>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- dLocal payer data. dLocal requires identity data in several LATAM markets. --}}
                    <div class="checkout-card mb-4" id="dlocalSection" style="display:none;">
                        <div class="checkout-card-header">
                            <i class="bi bi-person-vcard me-2 text-muted"></i>Datos del titular para dLocal
                        </div>
                        <div class="checkout-card-body">
                            <p class="text-muted small mb-3">Estos datos se envían de forma segura a dLocal para validar el pago. PRODEX no almacena datos de tarjeta.</p>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-600">País</label>
                                    <select name="dlocal_country" class="form-select dlocal-required" disabled>
                                        <option value="HN" {{ old('dlocal_country', 'HN') === 'HN' ? 'selected' : '' }}>Honduras</option>
                                        <option value="GT" {{ old('dlocal_country') === 'GT' ? 'selected' : '' }}>Guatemala</option>
                                        <option value="SV" {{ old('dlocal_country') === 'SV' ? 'selected' : '' }}>El Salvador</option>
                                        <option value="NI" {{ old('dlocal_country') === 'NI' ? 'selected' : '' }}>Nicaragua</option>
                                        <option value="CR" {{ old('dlocal_country') === 'CR' ? 'selected' : '' }}>Costa Rica</option>
                                        <option value="PA" {{ old('dlocal_country') === 'PA' ? 'selected' : '' }}>Panamá</option>
                                        <option value="MX" {{ old('dlocal_country') === 'MX' ? 'selected' : '' }}>México</option>
                                        <option value="CO" {{ old('dlocal_country') === 'CO' ? 'selected' : '' }}>Colombia</option>
                                        <option value="PE" {{ old('dlocal_country') === 'PE' ? 'selected' : '' }}>Perú</option>
                                        <option value="CL" {{ old('dlocal_country') === 'CL' ? 'selected' : '' }}>Chile</option>
                                        <option value="BR" {{ old('dlocal_country') === 'BR' ? 'selected' : '' }}>Brasil</option>
                                        <option value="AR" {{ old('dlocal_country') === 'AR' ? 'selected' : '' }}>Argentina</option>
                                        <option value="UY" {{ old('dlocal_country') === 'UY' ? 'selected' : '' }}>Uruguay</option>
                                        <option value="PY" {{ old('dlocal_country') === 'PY' ? 'selected' : '' }}>Paraguay</option>
                                        <option value="BO" {{ old('dlocal_country') === 'BO' ? 'selected' : '' }}>Bolivia</option>
                                        <option value="DO" {{ old('dlocal_country') === 'DO' ? 'selected' : '' }}>República Dominicana</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-600">Nombre completo del titular</label>
                                    <input type="text" name="dlocal_name" value="{{ old('dlocal_name') }}" maxlength="100" class="form-control dlocal-required" autocomplete="name" disabled>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-600">Documento de identidad</label>
                                    <input type="text" name="dlocal_document" value="{{ old('dlocal_document') }}" maxlength="30" class="form-control dlocal-required" autocomplete="off" disabled>
                                    <div class="form-text">En Honduras: DNI de 13 dígitos.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-600">Fecha de nacimiento</label>
                                    <input type="text" name="dlocal_birth_date" value="{{ old('dlocal_birth_date') }}" placeholder="DD-MM-AAAA" maxlength="10" class="form-control dlocal-required" autocomplete="bday" disabled>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-600">Teléfono</label>
                                    <input type="tel" name="dlocal_phone" value="{{ old('dlocal_phone', ($registration->metadata ?? [])['owner_phone'] ?? '') }}" maxlength="20" class="form-control dlocal-required" autocomplete="tel" disabled>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="checkout-card mb-4" id="offlineSection">
                        <div class="checkout-card-header">
                            <i class="bi bi-bank me-2 text-muted"></i>{{ __('central.BankTransferDetails') }}
                        </div>
                        <div class="checkout-card-body">
                            <div class="offline-amount-box mb-3">
                                <p class="mb-1 text-muted offline-amount-label">{{ __('central.AmountToTransfer') }}</p>
                                <p class="mb-0 fw-bold offline-amount-value">{{ $currencySymbol }}{{ number_format($amount, 2) }} {{ $currencyCode }}</p>
                            </div>

                            @if(!empty($bankDetails) && (($bankDetails['bank_name'] ?? '') || ($bankDetails['account_number'] ?? '')))
                            <div class="bank-details-grid mb-3">
                                @if($bankDetails['bank_name'] ?? '')
                                <div class="bank-detail-row"><span class="bank-detail-label">{{ __('central.BankName') }}</span><span class="bank-detail-value">{{ $bankDetails['bank_name'] }}</span></div>
                                @endif
                                @if($bankDetails['account_holder'] ?? '')
                                <div class="bank-detail-row"><span class="bank-detail-label">{{ __('central.AccountHolder') }}</span><span class="bank-detail-value">{{ $bankDetails['account_holder'] }}</span></div>
                                @endif
                                @if($bankDetails['account_number'] ?? '')
                                <div class="bank-detail-row"><span class="bank-detail-label">{{ __('central.AccountNumber') }}</span><span class="bank-detail-value">{{ $bankDetails['account_number'] }}</span></div>
                                @endif
                                @if($bankDetails['iban'] ?? '')
                                <div class="bank-detail-row"><span class="bank-detail-label">{{ __('central.IBAN') }}</span><span class="bank-detail-value">{{ $bankDetails['iban'] }}</span></div>
                                @endif
                                @if($bankDetails['swift'] ?? '')
                                <div class="bank-detail-row"><span class="bank-detail-label">{{ __('central.SwiftBic') }}</span><span class="bank-detail-value">{{ $bankDetails['swift'] }}</span></div>
                                @endif
                                @if($bankDetails['branch'] ?? '')
                                <div class="bank-detail-row"><span class="bank-detail-label">{{ __('central.Branch') }}</span><span class="bank-detail-value">{{ $bankDetails['branch'] }}</span></div>
                                @endif
                            </div>
                            @if($bankDetails['instructions'] ?? '')
                            <div class="bank-instructions mb-3">
                                <p class="mb-1 fw-600 bank-instructions-label">{{ __('central.Instructions') }}</p>
                                <p class="mb-0 text-muted bank-instructions-text">{{ $bankDetails['instructions'] }}</p>
                            </div>
                            @endif
                            @else
                            <div class="alert alert-warning d-flex align-items-center gap-2 mb-3 bank-not-configured">
                                <i class="bi bi-exclamation-triangle-fill"></i>{{ __('central.BankDetailsNotConfigured') }}
                            </div>
                            @endif

                            <hr class="checkout-separator">
                            <div class="mb-2">
                                <label class="form-label fw-600 upload-label"><i class="bi bi-upload me-1"></i> {{ __('central.UploadProofOfPayment') }} <span class="text-danger">*</span></label>
                                <div class="offline-upload-area" id="uploadArea">
                                    <input type="file" name="payment_proof" id="paymentProof" accept=".jpg,.jpeg,.png,.webp,.pdf" class="d-none">
                                    <div id="uploadPlaceholder">
                                        <i class="bi bi-cloud-arrow-up upload-icon-placeholder"></i>
                                        <p class="mb-1 fw-600 upload-label">{{ __('central.ClickOrDragFileHere') }}</p>
                                        <p class="mb-0 text-muted upload-hint-text">{{ __('central.AllowedFileTypes') }}</p>
                                    </div>
                                    <div id="uploadPreview">
                                        <i class="bi bi-file-earmark-check upload-icon-preview"></i>
                                        <p class="mb-0 fw-600 upload-file-name" id="uploadFileName"></p>
                                        <button type="button" class="btn btn-sm btn-outline-danger mt-1" id="removeFile"><i class="bi bi-x-lg"></i> {{ __('central.Remove') }}</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    @if(count($gateways) > 0)
                    <button type="submit" class="btn-pay" id="payBtn">
                        <i class="bi bi-lock-fill me-2"></i>
                        <span id="payBtnText">{{ __('central.PayAndCreateWorkspace', ['symbol' => $currencySymbol, 'amount' => number_format($amount, 2), 'code' => $currencyCode]) }}</span>
                    </button>
                    <p class="text-center text-muted mt-3 checkout-secure-note" id="secureNote"><i class="bi bi-shield-check"></i> {{ __('central.SecurePaymentNotice') }}</p>
                    @endif
                </form>
            </div>
        </div>
    </div>

    <script src="{{ asset('assets_super/js/bootstrap.bundle.min.js') }}"></script>
    @php $defaultBtnText = __('central.PayAndCreateWorkspace', ['symbol' => $currencySymbol, 'amount' => number_format($amount, 2), 'code' => $currencyCode]); @endphp
    <script>
    window.CheckoutData = {
        currencySymbol: @json($currencySymbol),
        currencyCode: @json($currencyCode),
        prices: { monthly: {{ $amount }}, yearly: {{ $amount }} },
        trans: {
            pay: @json($defaultBtnText),
            submitPaymentProof: @json(__('central.SubmitProofAndCreateWorkspace')),
            securePayment: @json(__('central.SecurePaymentNotice')),
            proofReviewed: @json(__('central.OfflineProofNotice')),
            fileTooLarge: @json(__('central.FileTooLarge')),
            submitting: @json(__('central.Submitting')),
            redirecting: @json(__('central.RedirectingToPayment')),
        },
    };

    document.addEventListener('DOMContentLoaded', function () {
        var section = document.getElementById('dlocalSection');
        function syncDLocalFields() {
            var selected = document.querySelector('.gateway-radio:checked');
            var active = !!selected && selected.value === 'dlocal';
            if (section) section.style.display = active ? 'block' : 'none';
            document.querySelectorAll('.dlocal-required').forEach(function (field) {
                field.disabled = !active;
            });
        }
        document.querySelectorAll('.gateway-radio').forEach(function (radio) {
            radio.addEventListener('change', syncDLocalFields);
        });
        syncDLocalFields();
    });
    </script>
    <script src="{{ asset('assets_super/js/checkout.js') }}"></script>
</body>
</html>
