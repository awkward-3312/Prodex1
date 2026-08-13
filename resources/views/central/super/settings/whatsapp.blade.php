@extends('central.super.layout')

@section('title', 'WhatsApp Settings')

@section('content')
<div class="page-header">
    <h1>WhatsApp Settings</h1>
    <p class="page-subtitle">Enable the WhatsApp Business API integration, choose a provider, manage default templates and monitor usage across tenants.</p>
</div>

<form method="POST" action="{{ route('super.settings.whatsapp.update') }}">
    @csrf
    @method('PUT')

    <div class="row g-4">
        <div class="col-lg-8">
            {{-- General --}}
            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-whatsapp me-2 text-muted"></i>Integration</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group mb-4">
                        <label class="form-check d-flex align-items-center gap-2">
                            <input type="checkbox" name="whatsapp_enabled" value="1" class="form-check-input"
                                   {{ old('whatsapp_enabled', $setting->whatsapp_enabled) ? 'checked' : '' }}>
                            <span class="fw-600">Enable WhatsApp integration globally</span>
                        </label>
                        <p class="form-hint">When off, tenants cannot send WhatsApp messages even if connected.</p>
                    </div>

                    <div class="form-group mb-4">
                        <label class="form-label">Provider</label>
                        <select name="whatsapp_provider" class="form-control">
                            @foreach($providers as $provider)
                                <option value="{{ $provider }}" {{ old('whatsapp_provider', $setting->whatsapp_provider) === $provider ? 'selected' : '' }}>
                                    {{ $provider === 'meta_cloud' ? 'Meta Cloud API' : ucfirst(str_replace('_', ' ', $provider)) }}
                                </option>
                            @endforeach
                        </select>
                        <p class="form-hint">Only the official Meta WhatsApp Business Cloud API is supported in this version.</p>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Tenant webhook URL</label>
                        <input type="text" class="form-control" readonly value="{{ $webhookBase }}/{tenant_id}">
                        <p class="form-hint">Each tenant configures their Meta app webhook to this URL with their own tenant ID. Status callbacks (delivered / read / failed) flow back here.</p>
                    </div>
                </div>
            </div>

            {{-- Default Templates --}}
            <div class="content-card mb-4">
                <div class="card-header-custom d-flex justify-content-between align-items-center">
                    <h2><i class="bi bi-chat-square-text me-2 text-muted"></i>Default Templates</h2>
                    <button type="button" class="btn btn-outline btn-sm" id="addTemplateBtn"><i class="bi bi-plus-lg"></i> Add</button>
                </div>
                <div class="card-body-custom">
                    <p class="text-muted fs-sm2 mb-3">Seeded into every newly provisioned tenant. Variables: <code>@{{customer_name}}</code>, <code>@{{invoice_number}}</code>, <code>@{{total_amount}}</code>, <code>@{{company_name}}</code>, <code>@{{payment_status}}</code>, <code>@{{order_status}}</code>.</p>

                    <div id="templatesWrap" class="d-flex flex-column gap-3">
                        @foreach($defaultTemplates as $i => $tpl)
                        <div class="template-row border rounded p-3">
                            <div class="row g-2 mb-2">
                                <div class="col-md-6">
                                    <label class="form-label fs-sm2">Key</label>
                                    <input type="text" name="templates[{{ $i }}][key]" class="form-control" value="{{ $tpl['key'] }}" placeholder="invoice_created">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fs-sm2">Name</label>
                                    <input type="text" name="templates[{{ $i }}][name]" class="form-control" value="{{ $tpl['name'] }}" placeholder="Invoice Created">
                                </div>
                            </div>
                            <label class="form-label fs-sm2">Message</label>
                            <textarea name="templates[{{ $i }}][body]" class="form-control" rows="2">{{ $tpl['body'] }}</textarea>
                            <button type="button" class="btn btn-link text-danger p-0 mt-2 removeTemplateBtn"><i class="bi bi-trash"></i> Remove</button>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="card-body-custom">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save WhatsApp Settings</button>
                </div>
            </div>
        </div>

        {{-- Usage sidebar --}}
        <div class="col-lg-4">
            <div class="content-card">
                <div class="card-header-custom">
                    <h2><i class="bi bi-bar-chart me-2 text-muted"></i>Usage This Month</h2>
                </div>
                <div class="card-body-custom">
                    @if(empty($usage))
                        <p class="text-muted fs-sm2 mb-0">No WhatsApp messages sent by any tenant this month yet.</p>
                    @else
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Tenant</th><th class="text-end">Sent</th></tr></thead>
                            <tbody>
                                @foreach($usage as $row)
                                <tr>
                                    <td>{{ $row['tenant'] }}</td>
                                    <td class="text-end fw-600">{{ number_format($row['sent']) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        </div>
    </div>
</form>

@push('scripts')
<script>
(function () {
    var wrap = document.getElementById('templatesWrap');
    var addBtn = document.getElementById('addTemplateBtn');

    function rowTemplate(i) {
        return '<div class="template-row border rounded p-3">' +
            '<div class="row g-2 mb-2">' +
            '<div class="col-md-6"><label class="form-label fs-sm2">Key</label>' +
            '<input type="text" name="templates[' + i + '][key]" class="form-control" placeholder="invoice_created"></div>' +
            '<div class="col-md-6"><label class="form-label fs-sm2">Name</label>' +
            '<input type="text" name="templates[' + i + '][name]" class="form-control" placeholder="Invoice Created"></div>' +
            '</div>' +
            '<label class="form-label fs-sm2">Message</label>' +
            '<textarea name="templates[' + i + '][body]" class="form-control" rows="2"></textarea>' +
            '<button type="button" class="btn btn-link text-danger p-0 mt-2 removeTemplateBtn"><i class="bi bi-trash"></i> Remove</button>' +
            '</div>';
    }

    if (addBtn) {
        addBtn.addEventListener('click', function () {
            var i = wrap.querySelectorAll('.template-row').length;
            wrap.insertAdjacentHTML('beforeend', rowTemplate(i));
        });
    }

    if (wrap) {
        wrap.addEventListener('click', function (e) {
            var btn = e.target.closest('.removeTemplateBtn');
            if (btn) { btn.closest('.template-row').remove(); }
        });
    }
})();
</script>
@endpush

@endsection
