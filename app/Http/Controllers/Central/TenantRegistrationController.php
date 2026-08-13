<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Central\WebhookController;
use App\Mail\TenantPendingApprovalMail;
use App\Mail\TenantUnderReviewMail;
use App\Models\Central\GeneralSetting;
use App\Models\Central\PendingRegistration;
use App\Models\Central\Plan;
use App\Models\Central\TenantSubscription;
use App\Services\PaymentGateways\PaymentGatewayFactory;
use App\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Stancl\Tenancy\Database\Models\Domain;

class TenantRegistrationController extends Controller
{
    public function showRegistrationForm()
    {
        $plans = Plan::public()->orderBy('price')->get();

        return view('central.register', compact('plans'));
    }

    public function register(Request $request)
    {
        // The select box only offers public (active + not private) plans, and the
        // empty option means "use the default starter plan". That empty default is
        // only valid when a public starter actually exists — otherwise the user
        // must explicitly pick a plan.
        $hasPublicStarter = Plan::public()->where('slug', 'starter')->exists();

        $validated = $request->validate([
            'subdomain' => [
                'required', 'string', 'min:2', 'max:63',
                'regex:/^[a-z0-9][a-z0-9\-]*[a-z0-9]$|^[a-z0-9]$/',
                function ($attribute, $value, $fail) {
                    if (GeneralSetting::instance()->isSubdomainReserved($value)) {
                        $fail(__('landing.subdomain_reserved'));
                    }
                    if (Domain::where('domain', $value)->exists()) {
                        $fail('This subdomain is already taken.');
                    }
                    if (PendingRegistration::where('subdomain', $value)->valid()->whereIn('status', ['pending', 'processing', 'paid', 'provisioning'])->exists()) {
                        $fail('This subdomain is already reserved.');
                    }
                },
            ],
            'company_name'   => ['required', 'string', 'min:2', 'max:255'],
            'admin_email'    => [
                'required', 'email',
                function ($attribute, $value, $fail) {
                    if (Tenant::where('data->admin_email', $value)->exists()) {
                        $fail('This email is already used by another tenant.');
                    }
                    if (PendingRegistration::where('admin_email', $value)->valid()->whereIn('status', ['pending', 'processing', 'paid', 'provisioning'])->exists()) {
                        $fail('This email is already associated with a pending registration.');
                    }
                },
            ],
            'admin_password' => ['required', 'string', 'min:8', 'confirmed'],
            'owner_phone'    => ['nullable', 'string', 'max:30', 'regex:/^[+0-9][0-9\s().-]{4,29}$/'],
            'plan_id'        => [
                $hasPublicStarter ? 'nullable' : 'required',
                function ($attribute, $value, $fail) {
                    if ($value !== null && $value !== '' && ! Plan::public()->whereKey($value)->exists()) {
                        $fail(__('landing.plan_invalid'));
                    }
                },
            ],
            'billing_cycle'  => ['nullable', 'in:monthly,yearly'],
        ], [
            'plan_id.required' => __('landing.plan_required'),
        ]);

        $plan = ! empty($validated['plan_id'])
            ? Plan::public()->find($validated['plan_id'])
            : Plan::public()->where('slug', 'starter')->first();

        if (! $plan) {
            return back()->withErrors(['plan_id' => 'No plans available. Please contact support.']);
        }

        $billingCycle = $validated['billing_cycle'] ?? 'monthly';

        if ($plan->isFree() || $plan->hasTrial()) {
            return $this->handleFreeOrTrialSignup($validated, $plan, $billingCycle);
        }

        return $this->handlePaidSignup($validated, $plan, $billingCycle);
    }

    // ── Free / trial: create tenant + provision immediately (VPS) or pending (Shared) ──

    protected function handleFreeOrTrialSignup(array $data, Plan $plan, string $billingCycle)
    {
        $settings = GeneralSetting::instance();
        $isShared = $settings->isSharedHosting();

        try {
            $tenant = Tenant::create([
                'id'                       => \Illuminate\Support\Str::uuid()->toString(),
                'company_name'             => $data['company_name'],
                'admin_email'              => $data['admin_email'],
                'admin_password_hash'      => Hash::make($data['admin_password']),
                'admin_password_encrypted' => Crypt::encryptString($data['admin_password']),
                'owner_phone'              => $data['owner_phone'] ?? null,
                'status'                   => $isShared ? Tenant::STATUS_PENDING : Tenant::STATUS_PROVISIONING,
            ]);

            $tenant->domains()->create(['domain' => $data['subdomain']]);

            $isTrial   = $plan->hasTrial();
            $trialDays = $plan->getTrialDays();
            $now       = now();

            TenantSubscription::create([
                'tenant_id'     => $tenant->id,
                'plan_id'       => $plan->id,
                'billing_cycle' => $billingCycle,
                'amount'        => $plan->getPriceForCycle($billingCycle),
                'currency'      => GeneralSetting::currencyCode(),
                'status'        => $isShared ? 'pending' : ($isTrial ? TenantSubscription::STATUS_TRIAL : TenantSubscription::STATUS_ACTIVE),
                'starts_at'     => $now,
                'trial_ends_at' => $isTrial ? $now->copy()->addDays($trialDays) : null,
                'ends_at'       => $isTrial
                    ? $now->copy()->addDays($trialDays)
                    : ($plan->isFree() ? null : ($billingCycle === 'yearly' ? $now->copy()->addYear() : $now->copy()->addMonth())),
            ]);
        } catch (\Throwable $e) {
            Log::error("Free/trial signup failed: {$e->getMessage()}", ['exception' => $e]);

            if (isset($tenant)) {
                $tenant->domains()->delete();
                TenantSubscription::where('tenant_id', $tenant->id)->delete();
                $tenant->delete();
            }

            return back()->withInput()->withErrors(['subdomain' => 'Signup failed. Please try again.']);
        }

        // ── Shared Hosting: send emails and show under-review page ──
        if ($isShared) {
            $this->sendSharedHostingEmails($tenant, $data, $settings);

            return redirect()->route('central.workspace.under-review', ['tenant' => $tenant->id]);
        }

        // ── VPS: provision immediately ──
        app(WebhookController::class)->dispatchProvisioning($tenant->id);

        return redirect()->route('central.workspace.preparing', ['tenant' => $tenant->id]);
    }

    protected function sendSharedHostingEmails(Tenant $tenant, array $data, GeneralSetting $settings): void
    {
        try {
            $tenantLocale = $tenant->locale ?? null;

            // Email to admin
            $adminEmail = $settings->email;
            if ($adminEmail) {
                Mail::to($adminEmail)->send(new TenantPendingApprovalMail(
                    $data['company_name'],
                    $data['subdomain'],
                    $data['admin_email'],
                    now()->format('M d, Y H:i'),
                    $tenantLocale
                ));
            }

            // Email to tenant user
            Mail::to($data['admin_email'])->send(new TenantUnderReviewMail(
                $data['company_name'],
                $settings->app_name ?? config('app.name'),
                $tenantLocale
            ));
        } catch (\Throwable $e) {
            Log::warning("Failed to send shared hosting notification emails: {$e->getMessage()}");
        }
    }

    // ── Paid: only create a pending registration — NO tenant ─────────

    protected function handlePaidSignup(array $data, Plan $plan, string $billingCycle)
    {
        $registration = PendingRegistration::create([
            'subdomain'           => $data['subdomain'],
            'company_name'        => $data['company_name'],
            'admin_email'         => $data['admin_email'],
            'admin_password_hash' => Hash::make($data['admin_password']),
            'plan_id'             => $plan->id,
            'billing_cycle'       => $billingCycle,
            'amount'              => $plan->getPriceForCycle($billingCycle),
            // Plan prices are in the platform's configured currency — storing a
            // hardcoded 'USD' here made CurrencyConversionService treat e.g. NGN
            // prices as USD and multiply them by the USD→NGN rate (~1500x),
            // which blew past the gateways' per-transaction limits.
            'currency'            => GeneralSetting::currencyCode(),
            'status'              => PendingRegistration::STATUS_PENDING,
            'metadata'            => [
                'admin_password_encrypted' => Crypt::encryptString($data['admin_password']),
                'owner_phone'              => $data['owner_phone'] ?? null,
            ],
        ]);

        return redirect()->route('central.checkout', ['token' => $registration->token]);
    }

    // ── Under review (shared hosting — pending admin approval) ──────

    public function underReview(Request $request, string $tenant)
    {
        $tenantModel = Tenant::find($tenant);

        if (! $tenantModel) {
            abort(404, 'Workspace not found.');
        }

        // If already active, redirect to ready page
        if ($tenantModel->isActive()) {
            return redirect()->route('central.workspace.ready', ['tenant' => $tenantModel->id]);
        }

        // If provisioning (VPS mode), redirect to preparing page
        if ($tenantModel->isProvisioning()) {
            return redirect()->route('central.workspace.preparing', ['tenant' => $tenantModel->id]);
        }

        $domain   = $tenantModel->domains()->first();
        $planName = $tenantModel->subscription?->plan?->name;
        $isPaid   = $tenantModel->payments()->exists();

        $settings = GeneralSetting::instance();

        return view('central.registration_under_review', [
            'companyName'  => $tenantModel->company_name ?? 'Your Company',
            'subdomain'    => $domain->domain ?? '—',
            'adminEmail'   => $tenantModel->admin_email ?? '—',
            'planName'     => $planName,
            'registeredAt' => $tenantModel->created_at->format('M d, Y \a\t h:i A'),
            'isPaid'       => $isPaid,
            'supportEmail' => $settings->email ?? ('support@' . request()->getHost()),
        ]);
    }

    // ── Preparing / status for free/trial tenants ────────────────────

    public function preparing(Request $request, string $tenant)
    {
        $tenantModel = Tenant::find($tenant);

        if (! $tenantModel) {
            abort(404, 'Workspace not found.');
        }

        // Pending tenants should see the under-review page, not the provisioning page
        if ($tenantModel->isPending()) {
            return redirect()->route('central.workspace.under-review', ['tenant' => $tenantModel->id]);
        }

        return view('central.workspace_preparing', [
            'tenantId'  => $tenantModel->id,
            'status'    => $tenantModel->status,
            'statusUrl' => route('central.workspace.status', ['tenant' => $tenantModel->id]),
        ]);
    }

    public function status(Request $request, string $tenant)
    {
        $tenantModel = Tenant::find($tenant);

        if (! $tenantModel) {
            return response()->json(['message' => 'Workspace not found.'], 404);
        }

        $data = [
            'status'     => $tenantModel->status,
            'tenant_url' => $tenantModel->getTenantUrl(),
        ];

        if ($tenantModel->isActive()) {
            $data['ready_url'] = route('central.workspace.ready', ['tenant' => $tenantModel->id]);
        }

        if ($tenantModel->isPending()) {
            $data['review_url'] = route('central.workspace.under-review', ['tenant' => $tenantModel->id]);
        }

        if ($tenantModel->status === 'failed') {
            $data = array_merge($data, $this->classifyProvisioningError($tenantModel->provisioning_error));
        }

        return response()->json($data);
    }

    // ── Preparing / status for paid registrations ────────────────────

    public function preparingRegistration(Request $request, string $token)
    {
        $registration = PendingRegistration::where('token', $token)->firstOrFail();

        // Gateways redirect back here with a status when the user cancels or the
        // charge fails (e.g. Flutterwave appends ?status=cancelled|failed to the
        // redirect URL). In that case never show the provisioning page — reset
        // the registration so it can be retried and send the user back to
        // checkout. A successful payment is NEVER trusted from this parameter;
        // it is always verified server-side (webhook or API verification).
        $returnStatus = strtolower((string) $request->query('status', ''));
        if (! $registration->tenant_id
            && $registration->isPending()
            && in_array($returnStatus, ['cancelled', 'canceled', 'failed'], true)
        ) {
            if ($registration->status === PendingRegistration::STATUS_PROCESSING) {
                $registration->update(['status' => PendingRegistration::STATUS_PENDING]);
            }

            return redirect()->route('central.checkout', ['token' => $registration->token, 'cancelled' => 1]);
        }

        if ($registration->tenant_id) {
            $tenant = Tenant::find($registration->tenant_id);

            if ($tenant) {
                // Already active → workspace ready
                if ($tenant->isActive()) {
                    return redirect()->route('central.workspace.ready', ['tenant' => $tenant->id]);
                }

                // Pending (shared hosting) → under review page
                if ($tenant->isPending()) {
                    return redirect()->route('central.workspace.under-review', ['tenant' => $tenant->id]);
                }
            }
        }

        $displayId = $registration->subdomain;
        $status = $registration->status;

        if (isset($tenant)) {
            $status = $tenant->status ?? $registration->status;
        }

        return view('central.workspace_preparing', [
            'tenantId'  => $displayId,
            'status'    => $status,
            'statusUrl' => route('central.registration.status', ['token' => $registration->token]),
        ]);
    }

    public function statusRegistration(Request $request, string $token)
    {
        $registration = PendingRegistration::where('token', $token)->first();

        if (! $registration) {
            return response()->json(['message' => 'Registration not found.'], 404);
        }

        // If tenant already exists, report its provisioning status
        if ($registration->tenant_id) {
            $tenant = Tenant::find($registration->tenant_id);

            if ($tenant) {
                $data = [
                    'status'     => $tenant->status,
                    'tenant_url' => $tenant->getTenantUrl(),
                ];

                if ($tenant->isActive()) {
                    $data['ready_url'] = route('central.workspace.ready', ['tenant' => $tenant->id]);
                }

                if ($tenant->isPending()) {
                    $data['review_url'] = route('central.workspace.under-review', ['tenant' => $tenant->id]);
                }

                if ($tenant->status === 'failed') {
                    $data = array_merge($data, $this->classifyProvisioningError($tenant->provisioning_error));
                }

                return response()->json($data);
            }
        }

        if ($registration->status === PendingRegistration::STATUS_FAILED) {
            // Payment never succeeded (no paid_at): let the user retry from
            // checkout instead of dead-ending on an error screen. A FAILED
            // registration WITH paid_at means provisioning failed after
            // payment — keep the error screen for that case.
            if (! $registration->paid_at && ! $registration->tenant_id) {
                $registration->update(['status' => PendingRegistration::STATUS_PENDING]);

                return response()->json([
                    'status'       => 'cancelled',
                    'checkout_url' => route('central.checkout', ['token' => $registration->token, 'cancelled' => 1]),
                ]);
            }

            return response()->json([
                'status'   => 'failed',
                'error'    => __('central.PaymentFailedError'),
                'solution' => __('central.PaymentFailedSolution'),
            ]);
        }

        // Fallback: if webhook hasn't arrived yet, actively verify with the gateway
        if ($registration->status === PendingRegistration::STATUS_PROCESSING
            && $registration->gateway
            && $registration->gateway_session_id
        ) {
            $outcome = $this->verifyPaymentWithGateway($registration);

            // Re-read in case verification triggered provisioning
            $registration->refresh();

            if ($registration->tenant_id) {
                $tenant = Tenant::find($registration->tenant_id);
                if ($tenant) {
                    $data = [
                        'status'     => $tenant->status,
                        'tenant_url' => $tenant->getTenantUrl(),
                    ];
                    if ($tenant->isActive()) {
                        $data['ready_url'] = route('central.workspace.ready', ['tenant' => $tenant->id]);
                    }
                    return response()->json($data);
                }
            }

            // The gateway reports the transaction as definitively failed,
            // cancelled or abandoned — reset the registration so the user can
            // retry, and send them back to the checkout page.
            if ($outcome === 'failed' && ! $registration->tenant_id) {
                if ($registration->status === PendingRegistration::STATUS_PROCESSING) {
                    $registration->update(['status' => PendingRegistration::STATUS_PENDING]);
                }

                return response()->json([
                    'status'       => 'cancelled',
                    'checkout_url' => route('central.checkout', ['token' => $registration->token, 'cancelled' => 1]),
                ]);
            }
        }

        // No tenant yet: either the payment is verified and provisioning is
        // about to start, or we are still waiting for payment confirmation.
        // Never report 'pending' here — the UI renders that as "payment
        // received, awaiting admin approval", which is wrong for an
        // unconfirmed payment.
        return response()->json([
            'status'     => $registration->isPaid() ? 'provisioning' : 'processing',
            'tenant_url' => '',
        ]);
    }

    protected function classifyProvisioningError(?string $raw): array
    {
        $lower = strtolower((string) $raw);

        // Database privileges (1044 = no DB privilege, 1045 = auth failure)
        if (str_contains($lower, 'access denied')
            || str_contains($lower, '1044')
            || str_contains($lower, '1045')) {
            return [
                'error'    => __('central.ErrorDatabaseAccess'),
                'solution' => __('central.SolutionDatabaseAccess'),
            ];
        }

        // Database server unreachable
        if (str_contains($lower, 'connection refused')
            || str_contains($lower, "can't connect")
            || str_contains($lower, 'no such host')
            || str_contains($lower, 'gone away')) {
            return [
                'error'    => __('central.ErrorDatabaseConnection'),
                'solution' => __('central.SolutionDatabaseConnection'),
            ];
        }

        return [
            'error'    => __('central.ProvisioningFailedError'),
            'solution' => __('central.ProvisioningFailedSolution'),
        ];
    }

    /**
     * Verify a processing payment directly with its gateway.
     *
     * Returns an outcome hint for the polling endpoint:
     *  - 'paid'    → verified successful (provisioning was triggered)
     *  - 'failed'  → the gateway reports a definitive failure/cancellation
     *  - 'pending' → still awaiting completion (or verification unavailable)
     */
    protected function verifyPaymentWithGateway(PendingRegistration $registration): string
    {
        try {
            if ($registration->gateway === 'stripe') {
                $this->verifyStripePayment($registration);
            } elseif ($registration->gateway === 'paypal') {
                $this->verifyPaypalPayment($registration);
            } elseif ($registration->gateway === 'paystack') {
                return $this->verifyPaystackPayment($registration);
            } elseif ($registration->gateway === 'flutterwave') {
                return $this->verifyFlutterwavePayment($registration);
            } elseif ($registration->gateway === 'mollie') {
                $this->verifyMolliePayment($registration);
            }
        } catch (\Throwable $e) {
            Log::warning("Payment verification fallback failed for registration {$registration->id}: {$e->getMessage()}");
        }

        return 'pending';
    }

    /**
     * Guard against amount/currency tampering or short-payments: the verified
     * gateway charge must cover the amount we asked for, in the currency we
     * asked for. Amounts are compared in major units.
     */
    protected function paidAmountMatches(PendingRegistration $registration, ?float $paidAmount, ?string $paidCurrency): bool
    {
        $expectedAmount   = (float) ($registration->gateway_amount ?? 0);
        $expectedCurrency = strtoupper((string) ($registration->gateway_currency ?? ''));

        if ($expectedAmount > 0 && $paidAmount !== null && $paidAmount + 0.01 < $expectedAmount) {
            Log::error("Registration {$registration->id}: paid amount {$paidAmount} is less than expected {$expectedAmount}.");
            return false;
        }

        if ($expectedCurrency !== '' && ! empty($paidCurrency) && strtoupper($paidCurrency) !== $expectedCurrency) {
            Log::error("Registration {$registration->id}: paid currency {$paidCurrency} does not match expected {$expectedCurrency}.");
            return false;
        }

        return true;
    }

    protected function verifyStripePayment(PendingRegistration $registration): void
    {
        $gateway = PaymentGatewayFactory::resolveForWebhook('stripe');

        if (! $gateway || ! method_exists($gateway, 'getCredential')) {
            return;
        }

        $secretKey = $gateway->getCredential('secret_key');

        if (empty($secretKey)) {
            return;
        }

        $stripe  = new \Stripe\StripeClient($secretKey);
        $session = $stripe->checkout->sessions->retrieve($registration->gateway_session_id);

        if ($session->payment_status !== 'paid') {
            return;
        }

        Log::info("Fallback verification: Stripe session {$session->id} is paid for registration {$registration->id}.");

        $registration->update([
            'status'             => PendingRegistration::STATUS_PAID,
            'gateway_payment_id' => $session->payment_intent ?? $session->id,
            'transaction_id'     => $session->payment_intent ?? $session->id,
            'paid_at'            => now(),
        ]);

        $webhook = app(WebhookController::class);
        $webhook->provisionFromRegistration($registration, 'stripe');
    }

    protected function verifyPaypalPayment(PendingRegistration $registration): void
    {
        $gateway = PaymentGatewayFactory::resolveForWebhook('paypal');

        if (! $gateway || ! method_exists($gateway, 'captureOrder')) {
            return;
        }

        $orderId = $registration->gateway_session_id;

        if (empty($orderId)) {
            return;
        }

        // PayPal Orders v2 requires an explicit capture after buyer approval.
        // captureOrder() handles ORDER_ALREADY_CAPTURED (e.g. webhook fired first)
        // by falling back to getOrderCapture(), so this is safe to call repeatedly.
        $capture = $gateway->captureOrder($orderId);

        if (empty($capture['success'])) {
            return;
        }

        Log::info("Fallback verification: PayPal order {$orderId} captured for registration {$registration->id}.");

        $registration->update([
            'status'             => PendingRegistration::STATUS_PAID,
            'gateway_payment_id' => $capture['order_id'] ?? $orderId,
            'transaction_id'     => $capture['capture_id'] ?? $orderId,
            'paid_at'            => now(),
        ]);

        $webhook = app(WebhookController::class);
        $webhook->provisionFromRegistration($registration, 'paypal');
    }

    protected function verifyPaystackPayment(PendingRegistration $registration): string
    {
        $gateway = PaymentGatewayFactory::resolveForWebhook('paystack');

        if (! $gateway || ! method_exists($gateway, 'verifyTransaction')) {
            return 'pending';
        }

        $reference = $registration->gateway_session_id;

        if (empty($reference)) {
            return 'pending';
        }

        $verification = $gateway->verifyTransaction($reference);

        if (empty($verification['success'])) {
            // "abandoned" is what Paystack reports when the customer cancels
            // the hosted checkout page.
            $status = strtolower((string) ($verification['status'] ?? ''));

            return in_array($status, ['failed', 'abandoned', 'reversed', 'cancelled'], true)
                ? 'failed'
                : 'pending';
        }

        // Paystack reports amounts in kobo — convert to major units.
        $paidAmount   = isset($verification['amount']) ? ((int) $verification['amount']) / 100 : null;
        $paidCurrency = $verification['currency'] ?? null;

        if (! $this->paidAmountMatches($registration, $paidAmount, $paidCurrency)) {
            return 'pending';
        }

        Log::info("Fallback verification: Paystack reference {$reference} is paid for registration {$registration->id}.");

        $registration->update([
            'status'             => PendingRegistration::STATUS_PAID,
            'gateway_payment_id' => $verification['reference'] ?? $reference,
            'transaction_id'     => $verification['transaction_id'] ?? $reference,
            'paid_at'            => now(),
        ]);

        $webhook = app(WebhookController::class);
        $webhook->provisionFromRegistration($registration, 'paystack');

        return 'paid';
    }

    protected function verifyFlutterwavePayment(PendingRegistration $registration): string
    {
        $gateway = PaymentGatewayFactory::resolveForWebhook('flutterwave');

        if (! $gateway || ! method_exists($gateway, 'verifyTransaction')) {
            return 'pending';
        }

        $reference = $registration->gateway_session_id;

        if (empty($reference)) {
            return 'pending';
        }

        $verification = $gateway->verifyTransaction($reference);

        if (empty($verification['success'])) {
            $status = strtolower((string) ($verification['status'] ?? ''));

            return in_array($status, ['failed', 'cancelled'], true)
                ? 'failed'
                : 'pending';
        }

        if (! $this->paidAmountMatches(
            $registration,
            isset($verification['amount']) ? (float) $verification['amount'] : null,
            $verification['currency'] ?? null
        )) {
            return 'pending';
        }

        Log::info("Fallback verification: Flutterwave tx_ref {$reference} is paid for registration {$registration->id}.");

        $registration->update([
            'status'             => PendingRegistration::STATUS_PAID,
            'gateway_payment_id' => $reference,
            'transaction_id'     => $verification['transaction_id'] ?? $reference,
            'paid_at'            => now(),
        ]);

        $webhook = app(WebhookController::class);
        $webhook->provisionFromRegistration($registration, 'flutterwave');

        return 'paid';
    }

    protected function verifyMolliePayment(PendingRegistration $registration): void
    {
        $gateway = PaymentGatewayFactory::resolveForWebhook('mollie');

        if (! $gateway || ! method_exists($gateway, 'verifyTransaction')) {
            return;
        }

        $reference = $registration->gateway_session_id;

        if (empty($reference)) {
            return;
        }

        $verification = $gateway->verifyTransaction($reference);

        if (empty($verification['success'])) {
            return;
        }

        Log::info("Fallback verification: Mollie payment {$reference} is paid for registration {$registration->id}.");

        $registration->update([
            'status'             => PendingRegistration::STATUS_PAID,
            'gateway_payment_id' => $reference,
            'transaction_id'     => $verification['transaction_id'] ?? $reference,
            'paid_at'            => now(),
        ]);

        $webhook = app(WebhookController::class);
        $webhook->provisionFromRegistration($registration, 'mollie');
    }

    // ── Workspace ready (confirmation page) ──────────────────────────

    public function workspaceReady(Request $request, string $tenant)
    {
        $tenantModel = Tenant::find($tenant);

        if (! $tenantModel || ! $tenantModel->isActive()) {
            if ($tenantModel && $tenantModel->isProvisioning()) {
                return redirect()->route('central.workspace.preparing', ['tenant' => $tenant]);
            }
            abort(404, 'Workspace not found or not yet ready.');
        }

        $adminEmail = $tenantModel->admin_email ?? '';
        $password   = null;

        if (! empty($tenantModel->admin_password_encrypted)) {
            try {
                $password = Crypt::decryptString($tenantModel->admin_password_encrypted);
            } catch (\Throwable $e) {
                Log::warning("Could not decrypt password for tenant {$tenant}: {$e->getMessage()}");
            }

            $tenantModel->admin_password_encrypted = null;
            $tenantModel->save();
        }

        $domain       = $tenantModel->domains()->first();
        $workspaceUrl = $tenantModel->getTenantUrl();
        $loginUrl     = $tenantModel->getLoginUrl();

        return view('central.workspace_ready', [
            'tenantId'     => $tenantModel->id,
            'companyName'  => $tenantModel->company_name ?? 'Your Company',
            'workspaceUrl' => $workspaceUrl,
            'loginUrl'     => $loginUrl,
            'adminEmail'   => $adminEmail,
            'password'     => $password,
            'subdomain'    => $domain->domain ?? '',
        ]);
    }

    protected function getBaseDomain(): string
    {
        $appUrl = config('app.url', 'http://localhost');
        return preg_replace('/^www\./i', '', parse_url($appUrl, PHP_URL_HOST) ?? 'localhost');
    }
}
