@extends('central.super.layout')

@section('title', __('central.PendingOfflinePayments'))

@section('content')
<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
        <h1><i class="bi bi-clock-history me-2"></i>{{ __('central.PendingOfflinePayments') }}</h1>
        <p class="page-subtitle">{{ __('central.PendingOfflinePaymentsSubtitle') }}</p>
    </div>
    <a href="{{ route('super.payments.dashboard') }}" class="btn btn-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> {{ __('central.BackToPayments') }}
    </a>
</div>

<div class="content-card">
    <div class="card-header-custom">
        <h2><i class="bi bi-bank me-2 text-muted"></i>{{ __('central.AwaitingVerification') }}</h2>
        <span class="text-muted fs-sm2">{{ $registrations->total() }} {{ Str::plural(__('central.Registration'), $registrations->total()) }}</span>
    </div>
    @if($registrations->isNotEmpty())
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>{{ __('central.Company') }}</th>
                    <th>{{ __('central.Email') }}</th>
                    <th>{{ __('central.Plan') }}</th>
                    <th>{{ __('central.Amount') }}</th>
                    <th>{{ __('central.Cycle') }}</th>
                    <th>{{ __('central.Proof') }}</th>
                    <th>{{ __('central.Date') }}</th>
                    <th class="action-cell">{{ __('central.Actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($registrations as $reg)
                <tr>
                    <td class="fw-600">{{ $reg->company_name }}</td>
                    <td class="fs-sm3">{{ $reg->admin_email }}</td>
                    <td class="fs-sm3">{{ $reg->plan->name ?? '—' }}</td>
                    <td class="fw-700 fs-sm3">{{ $reg->currency }} {{ number_format($reg->amount, 2) }}</td>
                    <td class="fs-sm2">{{ ucfirst($reg->billing_cycle) }}</td>
                    <td>
                        @if($reg->payment_proof_path)
                            @php
                                $ext = strtolower(pathinfo($reg->payment_proof_path, PATHINFO_EXTENSION));
                                $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'webp']);
                            @endphp
                            <a href="{{ asset($reg->payment_proof_path) }}" target="_blank" class="btn btn-outline btn-sm" title="{{ __('central.ViewProof') }}">
                                <i class="bi {{ $isImage ? 'bi-image' : 'bi-file-earmark-pdf' }}"></i> {{ __('central.View') }}
                            </a>
                        @else
                            <span class="text-muted fs-sm2">{{ __('central.NoProof') }}</span>
                        @endif
                    </td>
                    <td class="fs-sm2">{{ $reg->created_at->format('M d, Y') }}</td>
                    <td class="action-cell">
                        <div class="d-flex gap-1 justify-content-end">
                            <button type="button" class="btn btn-primary btn-sm btn-approve-registration"
                                    data-reg-company="{{ $reg->company_name }}"
                                    data-approve-url="{{ route('super.payments.pending-registrations.approve', $reg) }}"
                                    title="{{ __('central.Approve') }}">
                                <i class="bi bi-check-lg"></i>
                            </button>
                            <form method="POST" action="{{ route('super.payments.pending-registrations.reject', $reg) }}">
                                @csrf
                                <button type="submit" class="btn btn-outline-danger btn-sm" title="{{ __('central.Reject') }}" data-swal-confirm="{{ e(json_encode(['title' => __('central.RejectPaymentTitle'), 'text' => __('central.RejectPaymentText', ['company' => $reg->company_name]), 'icon' => 'warning', 'confirmButtonText' => __('central.Reject'), 'confirmButtonColor' => '#ef4444'])) }}">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if($registrations->hasPages())
    <div class="card-body-custom">
        {{ $registrations->links('vendor.pagination.bootstrap-5') }}
    </div>
    @endif
    @else
    <div class="empty-state">
        <div class="empty-icon"><i class="bi bi-check-circle"></i></div>
        <h3>{{ __('central.NoPendingPayments') }}</h3>
        <p>{{ __('central.AllProofsReviewed') }}</p>
    </div>
    @endif
</div>

@push('scripts')
<script>
window.PendingRegData = {
    csrfToken: @json(csrf_token()),
    trans: {
        approvePaymentTitle: @json(__('central.ApprovePaymentTitle')),
        approvePaymentText:  @json(__('central.ApprovePaymentText', ['company' => ':company'])),
        approve:             @json(__('central.Approve')),
        cancel:              @json(__('central.Cancel')),
        approvingTitle:      @json(__('central.ApprovingPaymentTitle')),
        approvingHtml:       @json(__('central.ApprovingPaymentHtml')),
        approvedTitle:       @json(__('central.PaymentApprovedTitle')),
        requestFailed:       @json(__('central.RequestFailed')),
    },
};
</script>
<script src="{{ asset('assets_super/js/swal-confirm-btn.js') }}"></script>
<script src="{{ asset('assets_super/js/pending-registrations.js') }}"></script>
@endpush

@endsection
