<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Central\WebhookController;
use App\Models\Central\PendingRegistration;
use App\Models\Central\Plan;
use App\Models\Central\TenantBillingPayment;
use App\Models\Central\TenantSubscription;
use App\Services\Billing\SubscriptionLifecycleService;
use App\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function dashboard(): View
    {
        $payments = TenantBillingPayment::on('central');
        $totalRevenue = (clone $payments)->where('status', 'paid')->sum('amount');
        $totalTax = (clone $payments)->where('status', 'paid')->sum('tax');
        $totalTransactions = (clone $payments)->count();
        $paidCount = (clone $payments)->where('status', 'paid')->count();
        $failedCount = (clone $payments)->where('status', 'failed')->count();
        $refundedCount = (clone $payments)->where('status', 'refunded')->count();
        $pendingCount = (clone $payments)->where('status', 'pending')->count();
        $monthlyRevenue = TenantBillingPayment::on('central')->where('status', 'paid')->where('paid_at', '>=', Carbon::now()->startOfMonth())->sum('amount');
        $revenueChart = TenantBillingPayment::on('central')->where('status', 'paid')->where('paid_at', '>=', Carbon::now()->subMonths(11)->startOfMonth())->select(DB::raw("DATE_FORMAT(paid_at, '%Y-%m') as month"), DB::raw('SUM(amount) as revenue'), DB::raw('COUNT(*) as count'))->groupBy('month')->orderBy('month')->get();
        $months = collect();
        for ($i = 11; $i >= 0; $i--) {
            $m = Carbon::now()->subMonths($i)->format('Y-m');
            $found = $revenueChart->firstWhere('month', $m);
            $months->push(['month' => Carbon::now()->subMonths($i)->format('M Y'), 'revenue' => $found ? (float) $found->revenue : 0, 'count' => $found ? $found->count : 0]);
        }
        $gatewayBreakdown = TenantBillingPayment::on('central')->where('status', 'paid')->select('gateway', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))->groupBy('gateway')->get();
        $recentPayments = TenantBillingPayment::on('central')->with(['tenant', 'plan'])->orderByDesc('created_at')->limit(10)->get();
        return view('central.super.payments.dashboard', compact('totalRevenue', 'totalTax', 'totalTransactions', 'paidCount', 'failedCount', 'refundedCount', 'pendingCount', 'monthlyRevenue', 'months', 'gatewayBreakdown', 'recentPayments'));
    }

    public function index(Request $request): View
    {
        $query = TenantBillingPayment::on('central')->with(['tenant', 'plan']);
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")->orWhere('transaction_id', 'like', "%{$search}%")->orWhere('gateway_payment_id', 'like', "%{$search}%")->orWhereHas('tenant', function ($tq) use ($search) { $tq->where('data->company_name', 'like', "%{$search}%"); });
            });
        }
        if ($status = $request->input('status')) $query->where('status', $status);
        if ($gateway = $request->input('gateway')) $query->where('gateway', $gateway);
        if ($planId = $request->input('plan_id')) $query->where('plan_id', $planId);
        if ($dateFrom = $request->input('date_from')) $query->whereDate('created_at', '>=', $dateFrom);
        if ($dateTo = $request->input('date_to')) $query->whereDate('created_at', '<=', $dateTo);
        $payments = $query->orderByDesc('created_at')->paginate(25)->withQueryString();
        $plans = Plan::on('central')->where('is_active', true)->orderBy('name')->get();
        return view('central.super.payments.index', compact('payments', 'plans'));
    }

    public function show(TenantBillingPayment $payment): View
    {
        $payment->load(['tenant.domains', 'plan', 'subscription.plan']);
        return view('central.super.payments.show', compact('payment'));
    }

    public function create(): View
    {
        $tenants = Tenant::with('subscription.plan')->orderBy('id')->get();
        $plans = Plan::on('central')->where('is_active', true)->orderBy('name')->get();
        return view('central.super.payments.create', compact('tenants', 'plans'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['required', 'string', 'exists:central.tenants,id'], 'plan_id' => ['nullable', 'integer', 'exists:central.plans,id'], 'amount' => ['required', 'numeric', 'min:0.01'], 'tax' => ['nullable', 'numeric', 'min:0'], 'currency' => ['required', 'string', 'size:3'], 'gateway' => ['required', 'string', 'in:' . implode(',', array_keys(TenantBillingPayment::GATEWAYS))], 'transaction_id' => ['nullable', 'string', 'max:255'], 'billing_cycle' => ['required', 'in:monthly,yearly'], 'status' => ['required', 'in:' . implode(',', TenantBillingPayment::STATUSES)], 'notes' => ['nullable', 'string'], 'paid_at' => ['nullable', 'date'],
        ]);
        $tenant = Tenant::find($validated['tenant_id']);
        $subscription = $tenant?->subscription;
        $validated['tenant_subscription_id'] = $subscription?->id;
        $validated['plan_id'] = $validated['plan_id'] ?? $subscription?->plan_id;
        $validated['tax'] = $validated['tax'] ?? 0;
        $validated['paid_at'] = $validated['status'] === 'paid' ? ($validated['paid_at'] ?? now()) : $validated['paid_at'];
        TenantBillingPayment::create($validated);
        return redirect()->route('super.payments.index')->with('success', 'Pago registrado correctamente.');
    }

    public function markPaid(TenantBillingPayment $payment, SubscriptionLifecycleService $lifecycle): RedirectResponse
    {
        if ($payment->isPaid()) return back()->with('info', 'El pago ya está marcado como pagado.');
        if ($payment->status !== TenantBillingPayment::STATUS_PENDING) return back()->with('error', 'Solo los pagos pendientes pueden marcarse como pagados. El estado actual de este pago es ' . $payment->status . '.');
        $result = $lifecycle->markPaid($payment);
        $message = 'Pago marcado como pagado.';
        if ($result['subscription_activated']) $message .= ' Suscripción activada.';
        elseif ($result['subscription_renewed']) $message .= ' Suscripción renovada.';
        if ($result['provisioning_dispatched']) $message .= ' Se inició la configuración del espacio de trabajo del tenant.';
        return back()->with('success', $message);
    }

    public function markFailed(TenantBillingPayment $payment, SubscriptionLifecycleService $lifecycle): RedirectResponse
    {
        if ($payment->status !== TenantBillingPayment::STATUS_PENDING) return back()->with('error', 'Solo los pagos pendientes pueden marcarse como fallidos.');
        $lifecycle->markFailed($payment);
        return back()->with('success', 'Pago marcado como fallido.');
    }

    public function markRefunded(TenantBillingPayment $payment, SubscriptionLifecycleService $lifecycle): RedirectResponse
    {
        $lifecycle->markRefunded($payment);
        return back()->with('success', 'Pago marcado como reembolsado.');
    }

    public function invoiceDownload(TenantBillingPayment $payment)
    {
        $payment->load(['tenant', 'plan']);
        $pdf = Pdf::loadView('central.super.payments.invoice-pdf', compact('payment'));
        return $pdf->download("invoice-{$payment->invoice_number}.pdf");
    }

    public function pendingRegistrations(Request $request): View
    {
        $registrations = PendingRegistration::where('gateway', 'offline')->where('status', PendingRegistration::STATUS_PROCESSING)->with('plan')->orderByDesc('created_at')->paginate(25);
        return view('central.super.payments.pending-registrations', compact('registrations'));
    }

    public function approveRegistration(PendingRegistration $registration, Request $request): RedirectResponse|JsonResponse
    {
        if ($registration->gateway !== 'offline' || $registration->status !== PendingRegistration::STATUS_PROCESSING) {
            $msg = 'Este registro no puede aprobarse.';
            return $request->wantsJson() ? response()->json(['success' => false, 'message' => $msg], 422) : back()->with('error', $msg);
        }
        try {
            $registration->update(['status' => PendingRegistration::STATUS_PAID, 'gateway_payment_id' => 'offline_approved_' . $registration->id, 'transaction_id' => 'offline_approved_' . $registration->id, 'paid_at' => now()]);
            $webhook = app(WebhookController::class);
            $webhook->provisionFromRegistration($registration, 'offline', skipProvisioning: true);
            $successMsg = "Pago aprobado para {$registration->company_name}. El tenant está pendiente de configurar su espacio de trabajo desde el panel.";
            if ($request->wantsJson()) return response()->json(['success' => true, 'company_name' => $registration->company_name, 'message' => $successMsg]);
            return back()->with('success', $successMsg);
        } catch (\Throwable $e) {
            Log::error("Failed to approve offline registration {$registration->id}: {$e->getMessage()}", ['exception' => $e]);
            $msg = 'No se pudo aprobar el registro: ' . $e->getMessage();
            return $request->wantsJson() ? response()->json(['success' => false, 'message' => $msg], 500) : back()->with('error', $msg);
        }
    }

    public function rejectRegistration(PendingRegistration $registration): RedirectResponse
    {
        if ($registration->gateway !== 'offline' || $registration->status !== PendingRegistration::STATUS_PROCESSING) return back()->with('error', 'Este registro no puede rechazarse.');
        $registration->update(['status' => PendingRegistration::STATUS_FAILED]);
        return back()->with('success', "El registro de {$registration->company_name} fue rechazado.");
    }

    public function invoices(Request $request): View
    {
        $query = TenantBillingPayment::on('central')->with(['tenant', 'plan'])->whereNotNull('invoice_number');
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) { $q->where('invoice_number', 'like', "%{$search}%")->orWhereHas('tenant', function ($tq) use ($search) { $tq->where('data->company_name', 'like', "%{$search}%"); }); });
        }
        if ($status = $request->input('status')) $query->where('status', $status);
        $invoices = $query->orderByDesc('created_at')->paginate(25)->withQueryString();
        return view('central.super.payments.invoices', compact('invoices'));
    }
}
