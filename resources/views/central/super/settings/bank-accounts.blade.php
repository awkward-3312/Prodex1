@extends('central.super.layout')

@section('title', 'Cuentas bancarias')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1"><i class="bi bi-bank me-2"></i>Cuentas bancarias</h1>
            <p class="text-muted mb-0">Administra las cuentas que PRODEX mostrará a los clientes que paguen por transferencia bancaria.</p>
        </div>
        <a href="{{ route('super.settings.general') }}#tab=payments" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Configuración general
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">
            <strong>No se pudieron guardar las cuentas.</strong>
            <ul class="mb-0 mt-2">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <div class="alert alert-info">
        <strong>Transferencias en Honduras:</strong> normalmente debes proporcionar banco, titular, número de cuenta, tipo de cuenta y moneda. IBAN y SWIFT/BIC se utilizan principalmente para transferencias internacionales y pueden dejarse vacíos si no aplican.
    </div>

    <form method="POST" action="{{ route('super.settings.bank-accounts.update') }}" id="bankAccountsForm">
        @csrf
        @method('PUT')

        <div id="bankAccountsList">
            @foreach(old('accounts', $accounts) as $index => $account)
                <div class="card shadow-sm mb-3 bank-account-card">
                    <div class="card-header d-flex align-items-center justify-content-between gap-2">
                        <strong class="bank-account-title">Cuenta bancaria {{ $index + 1 }}</strong>
                        <div class="d-flex align-items-center gap-3">
                            <div class="form-check form-switch mb-0">
                                <input type="hidden" name="accounts[{{ $index }}][active]" value="0">
                                <input class="form-check-input" type="checkbox" name="accounts[{{ $index }}][active]" value="1" {{ !empty($account['active']) ? 'checked' : '' }}>
                                <label class="form-check-label">Activa</label>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-danger js-remove-bank"><i class="bi bi-trash"></i> Eliminar</button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6"><label class="form-label">Banco *</label><input class="form-control" required name="accounts[{{ $index }}][bank_name]" value="{{ $account['bank_name'] ?? '' }}" placeholder="Ej. BAC Credomatic"></div>
                            <div class="col-md-6"><label class="form-label">Titular de la cuenta *</label><input class="form-control" required name="accounts[{{ $index }}][account_holder]" value="{{ $account['account_holder'] ?? '' }}" placeholder="Nombre exacto del titular"></div>
                            <div class="col-md-6"><label class="form-label">Número de cuenta *</label><input class="form-control" required name="accounts[{{ $index }}][account_number]" value="{{ $account['account_number'] ?? '' }}"></div>
                            <div class="col-md-3"><label class="form-label">Tipo de cuenta</label><select class="form-select" name="accounts[{{ $index }}][account_type]"><option value="savings" {{ ($account['account_type'] ?? 'savings') === 'savings' ? 'selected' : '' }}>Ahorros</option><option value="checking" {{ ($account['account_type'] ?? '') === 'checking' ? 'selected' : '' }}>Cheques</option></select></div>
                            <div class="col-md-3"><label class="form-label">Moneda</label><select class="form-select" name="accounts[{{ $index }}][currency]"><option value="HNL" {{ ($account['currency'] ?? 'HNL') === 'HNL' ? 'selected' : '' }}>Lempiras (HNL)</option><option value="USD" {{ ($account['currency'] ?? '') === 'USD' ? 'selected' : '' }}>Dólares (USD)</option></select></div>
                            <div class="col-md-4"><label class="form-label">Sucursal <span class="text-muted">(opcional)</span></label><input class="form-control" name="accounts[{{ $index }}][branch]" value="{{ $account['branch'] ?? '' }}"></div>
                            <div class="col-md-4"><label class="form-label">IBAN <span class="text-muted">(internacional/opcional)</span></label><input class="form-control" name="accounts[{{ $index }}][iban]" value="{{ $account['iban'] ?? '' }}"><div class="form-text">Déjalo vacío si tu banco no lo utiliza.</div></div>
                            <div class="col-md-4"><label class="form-label">SWIFT / BIC <span class="text-muted">(internacional/opcional)</span></label><input class="form-control" name="accounts[{{ $index }}][swift]" value="{{ $account['swift'] ?? '' }}"><div class="form-text">Código internacional del banco.</div></div>
                            <div class="col-12"><label class="form-label">Instrucciones específicas <span class="text-muted">(opcional)</span></label><textarea class="form-control" rows="2" name="accounts[{{ $index }}][instructions]" placeholder="Ej. Coloque el nombre de su empresa como referencia.">{{ $account['instructions'] ?? '' }}</textarea></div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="d-flex flex-wrap gap-2 justify-content-between">
            <button type="button" class="btn btn-outline-primary" id="addBankAccount"><i class="bi bi-plus-lg me-1"></i> Agregar cuenta bancaria</button>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Guardar cuentas</button>
        </div>
    </form>
</div>

<template id="bankAccountTemplate">
<div class="card shadow-sm mb-3 bank-account-card">
    <div class="card-header d-flex align-items-center justify-content-between gap-2"><strong class="bank-account-title"></strong><div class="d-flex align-items-center gap-3"><div class="form-check form-switch mb-0"><input type="hidden" data-field="active-hidden" value="0"><input class="form-check-input" data-field="active" type="checkbox" value="1" checked><label class="form-check-label">Activa</label></div><button type="button" class="btn btn-sm btn-outline-danger js-remove-bank"><i class="bi bi-trash"></i> Eliminar</button></div></div>
    <div class="card-body"><div class="row g-3">
        <div class="col-md-6"><label class="form-label">Banco *</label><input class="form-control" required data-field="bank_name" placeholder="Ej. BAC Credomatic"></div>
        <div class="col-md-6"><label class="form-label">Titular de la cuenta *</label><input class="form-control" required data-field="account_holder"></div>
        <div class="col-md-6"><label class="form-label">Número de cuenta *</label><input class="form-control" required data-field="account_number"></div>
        <div class="col-md-3"><label class="form-label">Tipo de cuenta</label><select class="form-select" data-field="account_type"><option value="savings">Ahorros</option><option value="checking">Cheques</option></select></div>
        <div class="col-md-3"><label class="form-label">Moneda</label><select class="form-select" data-field="currency"><option value="HNL">Lempiras (HNL)</option><option value="USD">Dólares (USD)</option></select></div>
        <div class="col-md-4"><label class="form-label">Sucursal <span class="text-muted">(opcional)</span></label><input class="form-control" data-field="branch"></div>
        <div class="col-md-4"><label class="form-label">IBAN <span class="text-muted">(internacional/opcional)</span></label><input class="form-control" data-field="iban"><div class="form-text">Déjalo vacío si tu banco no lo utiliza.</div></div>
        <div class="col-md-4"><label class="form-label">SWIFT / BIC <span class="text-muted">(internacional/opcional)</span></label><input class="form-control" data-field="swift"><div class="form-text">Código internacional del banco.</div></div>
        <div class="col-12"><label class="form-label">Instrucciones específicas <span class="text-muted">(opcional)</span></label><textarea class="form-control" rows="2" data-field="instructions"></textarea></div>
    </div></div>
</div>
</template>

<script>
(function () {
    const list = document.getElementById('bankAccountsList');
    const template = document.getElementById('bankAccountTemplate');
    const add = document.getElementById('addBankAccount');

    function reindex() {
        list.querySelectorAll('.bank-account-card').forEach((card, i) => {
            card.querySelector('.bank-account-title').textContent = 'Cuenta bancaria ' + (i + 1);
            card.querySelectorAll('[name]').forEach(el => el.name = el.name.replace(/accounts\[\d+\]/, 'accounts[' + i + ']'));
        });
    }

    function wire(card) {
        card.querySelector('.js-remove-bank').addEventListener('click', function () {
            card.remove();
            reindex();
        });
    }

    list.querySelectorAll('.bank-account-card').forEach(wire);

    add.addEventListener('click', function () {
        if (list.querySelectorAll('.bank-account-card').length >= 10) return alert('Puedes registrar hasta 10 cuentas bancarias.');
        const index = list.querySelectorAll('.bank-account-card').length;
        const card = template.content.firstElementChild.cloneNode(true);
        card.querySelectorAll('[data-field]').forEach(el => {
            const field = el.dataset.field;
            el.name = field === 'active-hidden' ? 'accounts[' + index + '][active]' : 'accounts[' + index + '][' + field + ']';
            el.removeAttribute('data-field');
        });
        list.appendChild(card);
        wire(card);
        reindex();
    });

    if (!list.querySelector('.bank-account-card')) add.click();
})();
</script>
@endsection
