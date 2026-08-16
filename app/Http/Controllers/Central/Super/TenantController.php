<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Central\WebhookController;
use App\Jobs\ProvisionTenantWorkspace;
use App\Mail\TenantApprovedMail;
use App\Mail\TenantRejectedMail;
use App\Models\Central\GeneralSetting;
use App\Models\Central\Plan;
use App\Models\Central\PendingRegistration;
use App\Models\Central\TenantBillingPayment;
use App\Models\Central\TenantSubscription;
use App\Models\User;
use App\Services\CurrencyConversionService;
use App\Services\PaymentGateways\PaymentGatewayFactory;
use App\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Stancl\Tenancy\Database\Models\Domain;

class TenantController extends Controller
{
    public function index(Request $request): View
    {
        $query = Tenant::with(['domains', 'subscription.plan']);

        if ($search = $request->input('search')) {
            $query->where('id', 'like', "%{$search}%");
        }

        if ($status = $request->input('status')) {
            if ($status === 'inactive') {
                $query->where('status', '!=', Tenant::STATUS_PENDING)
                    ->where(function ($q) {
                        $q->whereNull('last_activity_at')
                          ->orWhere('last_activity_at', '<', now()->subDays(30));
                    });
            } else {
                $query->where('status', $status);
            }
        }

        $tenants = $query->orderByDesc('created_at')->paginate(20)->withQueryString();

        return view('central.super.tenants.index', compact('tenants'));
    }

    public function create(): View
    {
        $plans = Plan::where('is_active', true)->orderBy('price')->get();
        $gateways = PaymentGatewayFactory::getAvailableGateways();

        return view('central.super.tenants.create', compact('plans', 'gateways'));
    }

    public function store(Request $request)
    {
        $rules = [
            'subdomain' => [
                'required', 'string', 'min:2', 'max:63',
                'regex:/^[a-z0-9][a-z0-9\-]*[a-z0-9]$|^[a-z0-9]$/',
                function ($attribute, $value, $fail) {
                    if (Domain::where('domain', $value)->exists()) {
                        $fail('This subdomain is already taken.');
                    }
                    if (PendingRegistration::where('subdomain', $value)->valid()->whereIn('status', ['pending', 'processing', 'paid', 'provisioning'])->exists()) {
                        $fail('This subdomain is already reserved.');
                    }
                },
            ],
            'country_code'   => ['nullable', 'string', 'size:2', 'in:HN,MX,GT,SV'],
            'company_name'   => ['required', 'string', 'min:2', 'max:255'],
            'admin_email'    => [
                'required', 'email', 'max:255',
                function ($attribute, $value, $fail) {
                    if (Tenant::where('data->admin_email', $value)->exists()) {
                        $fail('This email is already used by another tenant.');
                    }
                    if (PendingRegistration::where('admin_email', $value)->valid()->whereIn('status', ['pending', 'processing', 'paid', 'provisioning'])->exists()) {
                        $fail('This email is already associated with a pending registration.');
                    }
                },
            ],
            'admin_password' => ['required', 'string', 'min:8'],
            'owner_phone'    => ['nullable', 'string', 'max:30', 'regex:/^[+0-9][0-9\s().-]{4,29}$/'],
            'plan_id'        => ['required', 'exists:plans,id'],
            'billing_cycle'  => ['required', 'in:monthly,yearly'],
            'locale'         => ['nullable', 'string', 'max:10'],
        ];

        // Determine if payment is required before full validation
        $plan = Plan::find($request->input('plan_id'));
        $requiresPayment = $plan && ! $plan->isFree() && ! $plan->hasTrial();

        if ($requiresPayment) {
            $rules['gateway'] = ['required', 'string', 'in:stripe,paypal,paystack,flutterwave,mollie,offline'];
            $rules['payment_proof'] = ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:2048'];
        }

        $validated = $request->validate($rules);

        $plan = Plan::findOrFail($validated['plan_id']);
        $billingCycle = $validated['billing_cycle'];
        $settings = GeneralSetting::instance();
        $requiresPayment = ! $plan->isFree() && ! $plan->hasTrial();

        try {
            $tenantData = [
                'id'                       => Str::uuid()->toString(),
                'company_name'             => $validated['company_name'],
                'admin_email'              => $validated['admin_email'],
                'admin_password_hash'      => Hash::make($validated['admin_password']),
                'admin_password_encrypted' => Crypt::encryptString($validated['admin_password']),
                'owner_phone'              => $validated['owner_phone'] ?? null,
                'status'                   => Tenant::STATUS_PENDING,
            ];

            if (! empty($validated['country_code'])) {
                $tenantData['country_code'] = strtoupper($validated['country_code']);
            }

            if (! empty($validated['locale'])) {
                $tenantData['locale'] = $validated['locale'];
            }

            $tenant = Tenant::create($tenantData);

            $tenant->domains()->create(['domain' => $validated['subdomain']]);

            $isTrial   = $plan->hasTrial();
            $trialDays = $plan->getTrialDays();
            $now       = now();

            $subscription = TenantSubscription::create([
                'tenant_id'     => $tenant->id,
                'plan_id'       => $plan->id,
                'billing_cycle' => $billingCycle,
                'amount'        => $plan->getPriceForCycle($billingCycle),
                'currency'      => $settings->currency_code ?? 'USD',
                'status'        => 'pending',
                'starts_at'     => $now,
                'trial_ends_at' => $isTrial ? $now->copy()->addDays($trialDays) : null,
                'ends_at'       => $isTrial
                    ? $now->copy()->addDays($trialDays)
                    : ($plan->isFree() ? null : ($billingCycle === 'yearly' ? $now->copy()->addYear() : $now->copy()->addMonth())),
            ]);

            // ── Paid plan: create payment record & redirect to gateway ──
            if ($requiresPayment) {
                $gatewayKey = $validated['gateway'];
                $gateway = PaymentGatewayFactory::resolve($gatewayKey);

                if (! $gateway) {
                    throw new \RuntimeException('Selected payment gateway is not available.');
                }

                $amount = $plan->getPriceForCycle($billingCycle);
                $currency = $settings->currency_code ?? 'USD';

                // Resolve currency conversion for the gateway
                $currencyConfig = PaymentGatewayFactory::getGatewayCurrencyConfig($gatewayKey);
                $conversion = CurrencyConversionService::resolve(
                    (float) $amount,
                    $currency,
                    $currencyConfig['supported_currencies'],
                    $currencyConfig['default_currency']
                );

                $payment = TenantBillingPayment::create([
                    'tenant_id'              => $tenant->id,
                    'tenant_subscription_id' => $subscription->id,
                    'plan_id'                => $plan->id,
                    'amount'                 => $amount,
                    'currency'               => $currency,
                    'gateway_currency'       => $conversion['gateway_currency'],
                    'gateway_amount'         => $conversion['gateway_amount'],
                    'exchange_rate'          => $conversion['exchange_rate'],
                    'conversion_applied'     => $conversion['conversion_applied'],
                    'billing_cycle'          => $billingCycle,
                    'gateway'                => $gatewayKey,
                    'status'                 => TenantBillingPayment::STATUS_PENDING,
                ]);

                // Offline gateway: admin-created — mark payment as paid immediately
                if ($gatewayKey === 'offline') {
                    $updateData = [
                        'gateway_payment_id' => 'offline_' . $payment->id,
                        'status'             => TenantBillingPayment::STATUS_PAID,
                        'paid_at'            => now(),
                    ];

                    if ($request->hasFile('payment_proof')) {
                        $file = $request->file('payment_proof');
                        $filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
                        $file->move(public_path('images/super/payment-proofs'), $filename);
                        $updateData['payment_proof_path'] = 'images/super/payment-proofs/' . $filename;
                    }

                    $payment->update($updateData);

                    return redirect()->route('super.tenants.show', $tenant)
                        ->with('success', 'Tenant created with payment confirmed. Approve to provision the workspace.');
                }

                $successUrl = route('super.tenants.payment-preparing', $tenant);
                $cancelUrl  = route('super.tenants.show', $tenant) . '?payment=cancelled';

                $checkoutUrl = $gateway->createCheckoutSession($payment, $successUrl, $cancelUrl);

                return redirect()->away($checkoutUrl);
            }

            // ── Free / Trial: no payment needed — redirect to tenant page for approval ──
            return redirect()->route('super.tenants.show', $tenant)
                ->with('success', 'Tenant created in pending status. Approve to provision the workspace.');

        } catch (\Throwable $e) {
            Log::error("Admin tenant creation failed: {$e->getMessage()}", ['exception' => $e]);

            if (isset($tenant)) {
                $tenant->domains()->delete();
                TenantSubscription::where('tenant_id', $tenant->id)->delete();
                if (isset($payment)) {
                    $payment->delete();
                }
                $tenant->delete();
            }

            return back()->withInput()->with('error', 'Failed to create tenant: ' . $e->getMessage());
        }
    }

    public function show(Tenant $tenant): View
    {
        $tenant->load(['domains', 'customDomains', 'subscription.plan']);

        // Gather usage stats by querying the tenant's isolated database
        // Note: $tenant->run() initializes tenancy which rewrites asset() URLs.
        // If run() throws, tenancy is NOT reverted — we must restore manually.
        $usageStats = [];
        if ($tenant->subscription?->plan) {
            try {
                $tenant->run(function () use (&$usageStats) {
                    foreach (Plan::AVAILABLE_LIMITS as $key => $meta) {
                        if (isset($meta['model']) && class_exists($meta['model'])) {
                            $usageStats[$key] = $meta['model']::count();
                        } else {
                            $usageStats[$key] = 0;
                        }
                    }
                });
            } catch (\Throwable) {
                $this->revertTenancyAssets();
                foreach (Plan::AVAILABLE_LIMITS as $key => $meta) {
                    $usageStats[$key] = null;
                }
            }
        }

        // Fetch tenant users (limited to first 5 for overview)
        $tenantUsers = [];
        $tenantUserCount = 0;
        try {
            $tenant->run(function () use (&$tenantUsers, &$tenantUserCount) {
                $tenantUserCount = User::count();
                $tenantUsers = User::with('roles')->orderBy('id')->limit(5)->get();
            });
        } catch (\Throwable) {
            $this->revertTenancyAssets();
        }

        return view('central.super.tenants.show', compact('tenant', 'usageStats', 'tenantUsers', 'tenantUserCount'));
    }

    public function edit(Tenant $tenant): View
    {
        $tenant->load('domains');

        return view('central.super.tenants.edit', compact('tenant'));
    }

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:pending,provisioning,active,suspended,cancelled,failed,rejected'],
            'country_code' => ['nullable', 'string', 'size:2', 'in:HN,MX,GT,SV'],
            'locale' => ['nullable', 'string', 'max:10'],
            'owner_phone' => ['nullable', 'string', 'max:30', 'regex:/^[+0-9][0-9\s().-]{4,29}$/'],
        ]);

        $isProvisioned = in_array($tenant->status, [
            Tenant::STATUS_ACTIVE,
            Tenant::STATUS_SUSPENDED,
            Tenant::STATUS_CANCELLED,
        ], true);

        if (! $isProvisioned && in_array($validated['status'], ['active', 'suspended'], true)) {
            return back()
                ->withInput()
                ->with('error', __('super.tenants.activate_requires_provision_error'));
        }

        $updateData = [];

        // Only update status if it actually changed.
        if ($validated['status'] !== $tenant->status) {
            $updateData['status'] = $validated['status'];
        }

        if (array_key_exists('country_code', $validated)) {
            $updateData['country_code'] = $validated['country_code'] ? strtoupper($validated['country_code']) : null;
        }

        if (array_key_exists('locale', $validated)) {
            $updateData['locale'] = $validated['locale'];
        }

        if (array_key_exists('owner_phone', $validated)) {
            $updateData['owner_phone'] = $validated['owner_phone'];
        }

        $tenant->update($updateData);

        return redirect()->route('super.tenants.show', $tenant)->with('success', 'Tenant updated.');
    }

    public function suspend(Tenant $tenant): RedirectResponse
    {
        // Remember the pre-suspension status so reactivate() can restore it.
        // Guard against double-suspends overwriting the original value.
        if ($tenant->status !== Tenant::STATUS_SUSPENDED) {
            $tenant->status_before_suspension = $tenant->status;
        }
        $tenant->status = Tenant::STATUS_SUSPENDED;
        $tenant->save();

        return back()->with('success', 'Tenant suspended.');
    }

    public function reactivate(Tenant $tenant): RedirectResponse
    {
        $previous = $tenant->status_before_suspension ?: Tenant::STATUS_ACTIVE;

        $tenant->status = $previous;
        $tenant->status_before_suspension = null;
        $tenant->save();

        return back()->with('success', 'Tenant reactivated.');
    }

    public function cancel(Tenant $tenant): RedirectResponse
    {
        $tenant->update(['status' => 'cancelled']);

        return back()->with('success', 'Tenant cancelled.');
    }

    public function approve(Request $request, Tenant $tenant): RedirectResponse
    {
        if (! $tenant->isPending()) {
            return back()->with('error', 'This tenant is not in pending status.');
        }

        // Payment guard: tenant must be paid, free, or on trial
        $plan = $tenant->subscription?->plan;
        $isFreeOrTrial = $plan && ($plan->isFree() || $plan->hasTrial());

        if (! $isFreeOrTrial) {
            $hasPaidPayment = $tenant->payments()
                ->where('status', TenantBillingPayment::STATUS_PAID)
                ->exists();

            if (! $hasPaidPayment) {
                return back()->with('error', 'Payment must be confirmed before approval. Mark the payment as paid first.');
            }
        }

        // Always require DB credentials (admin provides them in both shared and VPS modes via the approve form)
        $request->validate([
            'db_host'     => ['required', 'string', 'max:255'],
            'db_name'     => ['required', 'string', 'max:255'],
            'db_username' => ['required', 'string', 'max:255'],
            'db_password' => ['nullable', 'string', 'max:255'],
            'db_port'     => ['required', 'integer', 'min:1', 'max:65535'],
        ]);

        // Save encrypted credentials
        $tenant->setDatabaseCredentials(
            $request->input('db_host'),
            $request->input('db_name'),
            $request->input('db_username'),
            $request->input('db_password') ?? '',
            (int) $request->input('db_port')
        );

        // Test connection (auto-create database if it doesn't exist)
        try {
            $this->attemptDatabaseConnection($tenant->getDatabaseCredentials());
        } catch (\PDOException $e) {
            if ($e->getCode() == 1049) {
                try {
                    $creds = $tenant->getDatabaseCredentials();
                    $pdo = $this->attemptServerConnection($creds);

                    $dbName    = $creds['database'];
                    $charset   = config('database.connections.central.charset', 'utf8mb4');
                    $collation = config('database.connections.central.collation', 'utf8mb4_unicode_ci');

                    $pdo->exec("CREATE DATABASE `{$dbName}` CHARACTER SET {$charset} COLLATE {$collation}");
                } catch (\Throwable $createErr) {
                    return back()->with('error', "Database \"{$creds['database']}\" does not exist and could not be created: {$createErr->getMessage()}")
                                 ->withInput();
                }
            } else {
                return back()->with('error', "Connection failed: {$e->getMessage()}")
                             ->withInput();
            }
        } catch (\Throwable $e) {
            return back()->with('error', "Connection failed: {$e->getMessage()}")
                         ->withInput();
        }

        // Run provisioning after the HTTP response is sent (works for both shared and VPS)
        $tenantId = $tenant->id;
        app()->terminating(function () use ($tenantId) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            try {
                $job = new ProvisionTenantWorkspace($tenantId);
                $job->handle();

                $tenant = Tenant::find($tenantId);
                if (! $tenant) {
                    return;
                }

                // Activate subscription on success
                if ($tenant->status === Tenant::STATUS_ACTIVE) {
                    $subscription = TenantSubscription::where('tenant_id', $tenantId)->first();
                    if ($subscription && $subscription->status === 'pending') {
                        $isTrial = $subscription->trial_ends_at !== null;
                        $subscription->update([
                            'status' => $isTrial ? TenantSubscription::STATUS_TRIAL : TenantSubscription::STATUS_ACTIVE,
                        ]);
                    }

                    // Send approval email
                    try {
                        $settings = GeneralSetting::instance();
                        $appName = $settings->app_name ?? config('app.name');

                        Mail::to($tenant->admin_email)->send(new TenantApprovedMail(
                            $tenant->company_name ?? 'Your Company',
                            $tenant->getTenantUrl(),
                            $tenant->getLoginUrl(),
                            $appName,
                            $tenant->locale
                        ));
                    } catch (\Throwable $e) {
                        Log::warning("Failed to send tenant approval email: {$e->getMessage()}");
                    }
                }
            } catch (\Throwable $e) {
                Log::error("Approve provisioning failed for tenant {$tenantId}: {$e->getMessage()}");
                $tenant = Tenant::find($tenantId);
                if ($tenant) {
                    $tenant->update(['status' => Tenant::STATUS_PENDING, 'provisioning_error' => $e->getMessage()]);
                }
            }
        });

        return back()->with('success', 'Tenant approved. Provisioning started — refresh this page to check progress.');
    }

    /**
     * Test database credentials (AJAX).
     * Accepts credentials from request body (pre-save test) or falls back to stored credentials.
     */
    public function testConnection(Request $request, Tenant $tenant): JsonResponse
    {
        // If request contains credentials, use those (pre-save test from approve form)
        if ($request->filled('db_name')) {
            $request->validate([
                'db_host'     => ['required', 'string', 'max:255'],
                'db_name'     => ['required', 'string', 'max:255'],
                'db_username' => ['required', 'string', 'max:255'],
                'db_password' => ['nullable', 'string', 'max:255'],
                'db_port'     => ['required', 'integer', 'min:1', 'max:65535'],
            ]);

            $creds = [
                'host'     => $request->input('db_host'),
                'database' => $request->input('db_name'),
                'username' => $request->input('db_username'),
                'password' => $request->input('db_password') ?? '',
                'port'     => (int) $request->input('db_port'),
            ];
        } else {
            $creds = $tenant->getEffectiveDatabaseCredentials();
        }

        try {
            $this->attemptDatabaseConnection($creds);

            return response()->json(['success' => true, 'message' => 'Connection successful.']);
        } catch (\Throwable $e) {
            // Check if this is specifically "unknown database" (MySQL error 1049)
            if ($e instanceof \PDOException && $e->getCode() == 1049) {
                // Verify server credentials are valid by connecting without a database
                try {
                    $this->attemptServerConnection($creds);

                    return response()->json([
                        'success'      => false,
                        'db_not_found' => true,
                        'message'      => "Database \"{$creds['database']}\" does not exist. Server credentials are valid — you can create it.",
                    ]);
                } catch (\Throwable) {
                    // Server credentials are also invalid
                }
            }

            // Capture database connection failure for super admin monitoring
            \App\Models\Central\SystemLog::capture([
                'tenant_id'       => $tenant->id,
                'type'            => \App\Models\Central\SystemLog::TYPE_DATABASE,
                'severity'        => \App\Models\Central\SystemLog::SEVERITY_CRITICAL,
                'message'         => 'Database connection failed for tenant ' . $tenant->id . ': ' . $e->getMessage(),
                'context'         => [
                    'host'     => $creds['host'] ?? null,
                    'database' => $creds['database'] ?? null,
                    'port'     => $creds['port'] ?? null,
                    'code'     => $e->getCode(),
                ],
                'suggested_cause' => $this->dbSuggestedCause($e),
                'source'          => 'tenant.test_connection',
            ]);

            return response()->json(['success' => false, 'message' => "Connection failed: {$e->getMessage()}"]);
        }
    }

    /**
     * Map common database errors to a human-readable cause hint.
     */
    private function dbSuggestedCause(\Throwable $e): string
    {
        $code = (int) $e->getCode();
        $msg  = $e->getMessage();

        if ($code === 1049)                         return 'Database does not exist. Create it or fix the database name.';
        if ($code === 1045)                         return 'Access denied — check the database username and password.';
        if ($code === 2002 || str_contains($msg, 'refused')) return 'Cannot reach the database server. Verify host/port and that the MySQL service is running.';
        if (str_contains($msg, 'permission') || str_contains($msg, 'GRANT')) return 'The database user lacks required privileges. Grant SELECT/INSERT/UPDATE/DELETE and schema permissions.';
        if (str_contains($msg, 'timed out'))        return 'Connection timed out. Check network/firewall between the app server and the database.';
        return 'Verify host, port, database name, credentials, and that the MySQL user has the right privileges.';
    }

    /**
     * Check tenant provisioning status (AJAX polling).
     */
    public function provisioningStatus(Tenant $tenant): JsonResponse
    {
        return response()->json([
            'status' => $tenant->status,
            'error'  => $tenant->provisioning_error,
        ]);
    }

    /**
     * Post-payment preparing page — shows provisioning progress after gateway payment.
     * Verifies the payment with the gateway on load as a fallback for webhooks.
     */
    public function paymentPreparing(Tenant $tenant)
    {
        // Verify pending payment directly with the gateway (fallback if webhook hasn't fired)
        $this->verifyPendingPayment($tenant);

        // Reload tenant status after potential payment verification
        $tenant->refresh();

        // Shared hosting: payment done but tenant needs DB credentials/approval — redirect to under-review
        $settings = GeneralSetting::instance();
        if ($settings->isSharedHosting() && $tenant->isPending()) {
            return redirect()->route('central.workspace.under-review', ['tenant' => $tenant->id]);
        }

        return view('central.workspace_preparing', [
            'tenantId'  => $tenant->domains()->first()->domain ?? $tenant->id,
            'status'    => $tenant->status,
            'statusUrl' => route('super.tenants.payment-status', $tenant),
        ]);
    }

    /**
     * Post-payment status polling endpoint — returns data compatible with workspace_preparing view.
     * Also verifies pending payment as a webhook fallback.
     */
    public function paymentStatusPoll(Tenant $tenant): JsonResponse
    {
        // If tenant is still pending, try to verify the payment with the gateway
        if ($tenant->isPending()) {
            $this->verifyPendingPayment($tenant);
            $tenant->refresh();
        }

        $data = [
            'status'     => $tenant->status,
            'tenant_url' => $tenant->getTenantUrl(),
        ];

        if ($tenant->isActive()) {
            $data['ready_url'] = route('super.tenants.show', $tenant);
        }

        // Shared hosting + pending → redirect to under-review
        if ($tenant->isPending()) {
            $data['review_url'] = route('central.workspace.under-review', ['tenant' => $tenant->id]);
        }

        if ($tenant->status === 'failed') {
            $data['error'] = $tenant->provisioning_error ?? 'Provisioning failed.';
        }

        return response()->json($data);
    }

    /**
     * Verify a pending billing payment directly with the gateway.
     * Acts as a fallback when the webhook hasn't fired (e.g. local development).
     */
    protected function verifyPendingPayment(Tenant $tenant): void
    {
        $payment = TenantBillingPayment::where('tenant_id', $tenant->id)
            ->where('status', TenantBillingPayment::STATUS_PENDING)
            ->whereNotNull('gateway')
            ->whereNotNull('gateway_payment_id')
            ->latest()
            ->first();

        if (! $payment) {
            return;
        }

        $gateway = PaymentGatewayFactory::resolveForWebhook($payment->gateway);
        if (! $gateway) {
            return;
        }

        try {
            $result = $gateway->verifyPaymentStatus($payment->gateway_payment_id);

            if ($result['status'] === 'paid') {
                $payment->markPaid(
                    $result['gateway_payment_id'] ?? null,
                    $result['transaction_id'] ?? null
                );

                $subscription = $payment->subscription;
                if ($subscription && $subscription->status !== TenantSubscription::STATUS_ACTIVE) {
                    $subscription->activate();
                }

                // Dispatch provisioning for VPS mode
                $settings = GeneralSetting::instance();
                if (! $settings->isSharedHosting()) {
                    app(WebhookController::class)->dispatchProvisioning($tenant->id);
                }

                Log::info("Payment {$payment->id} verified as paid via direct gateway check for tenant {$tenant->id}.");
            }
        } catch (\Throwable $e) {
            Log::warning("Direct payment verification failed for payment {$payment->id}: {$e->getMessage()}");
        }
    }

    /**
     * Create a tenant database (AJAX).
     */
    public function createDatabase(Request $request, Tenant $tenant): JsonResponse
    {
        // If the request has form fields, use them; otherwise fall back to the
        // tenant's effective credentials (VPS tenants have no editable form).
        if ($request->filled('db_name')) {
            $request->validate([
                'db_host'     => ['required', 'string', 'max:255'],
                'db_name'     => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9_-]+$/'],
                'db_username' => ['required', 'string', 'max:255'],
                'db_password' => ['nullable', 'string', 'max:255'],
                'db_port'     => ['required', 'integer', 'min:1', 'max:65535'],
            ]);

            $creds = [
                'host'     => $request->input('db_host'),
                'database' => $request->input('db_name'),
                'username' => $request->input('db_username'),
                'password' => $request->input('db_password') ?? '',
                'port'     => (int) $request->input('db_port'),
            ];
        } else {
            $creds = $tenant->getEffectiveDatabaseCredentials();
        }

        try {
            $pdo = $this->attemptServerConnection($creds);

            $dbName  = $creds['database'];
            $charset = config('database.connections.central.charset', 'utf8mb4');
            $collation = config('database.connections.central.collation', 'utf8mb4_unicode_ci');

            $pdo->exec("CREATE DATABASE `{$dbName}` CHARACTER SET {$charset} COLLATE {$collation}");

            return response()->json(['success' => true, 'message' => "Database \"{$dbName}\" created successfully."]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => "Failed to create database: {$e->getMessage()}"]);
        }
    }

    /**
     * Re-run tenant migrations (for admin maintenance).
     */
    public function rerunMigrations(Tenant $tenant): RedirectResponse
    {
        if (! $tenant->hasDatabaseCredentials()) {
            return back()->with('error', 'No database credentials configured for this tenant.');
        }

        try {
            $this->attemptDatabaseConnection($tenant->getDatabaseCredentials());
        } catch (\Throwable $e) {
            return back()->with('error', "Cannot connect to tenant database: {$e->getMessage()}");
        }

        try {
            $tenant->run(function () {
                Artisan::call('migrate', [
                    '--path'     => [database_path('migrations/tenant')],
                    '--realpath' => true,
                    '--force'    => true,
                ]);

                // Only run Passport migrations if tables don't exist yet
                if (!\Illuminate\Support\Facades\Schema::hasTable('oauth_auth_codes')) {
                    Artisan::call('migrate', [
                        '--path'  => 'vendor/laravel/passport/database/migrations',
                        '--force' => true,
                    ]);
                }
            });

            return back()->with('success', 'Migrations re-run successfully.');
        } catch (\Throwable $e) {
            return back()->with('error', "Migration failed: {$e->getMessage()}");
        }
    }

    /**
     * Test database connection without requiring a tenant (for create form).
     */
    public function testConnectionPreSave(Request $request): JsonResponse
    {
        $request->validate([
            'db_host'     => ['required', 'string', 'max:255'],
            'db_name'     => ['required', 'string', 'max:255'],
            'db_username' => ['required', 'string', 'max:255'],
            'db_password' => ['nullable', 'string', 'max:255'],
            'db_port'     => ['required', 'integer', 'min:1', 'max:65535'],
        ]);

        $creds = [
            'host'     => $request->input('db_host'),
            'database' => $request->input('db_name'),
            'username' => $request->input('db_username'),
            'password' => $request->input('db_password') ?? '',
            'port'     => (int) $request->input('db_port'),
        ];

        try {
            $this->attemptDatabaseConnection($creds);

            return response()->json(['success' => true, 'message' => 'Connection successful.']);
        } catch (\Throwable $e) {
            if ($e instanceof \PDOException && $e->getCode() == 1049) {
                try {
                    $this->attemptServerConnection($creds);

                    return response()->json([
                        'success'      => false,
                        'db_not_found' => true,
                        'message'      => "Database \"{$creds['database']}\" does not exist. Server credentials are valid — you can create it.",
                    ]);
                } catch (\Throwable) {
                    // Server credentials are also invalid
                }
            }

            return response()->json(['success' => false, 'message' => "Connection failed: {$e->getMessage()}"]);
        }
    }

    /**
     * Create a database without requiring a tenant (for create form).
     */
    public function createDatabasePreSave(Request $request): JsonResponse
    {
        $request->validate([
            'db_host'     => ['required', 'string', 'max:255'],
            'db_name'     => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'db_username' => ['required', 'string', 'max:255'],
            'db_password' => ['nullable', 'string', 'max:255'],
            'db_port'     => ['required', 'integer', 'min:1', 'max:65535'],
        ]);

        $creds = [
            'host'     => $request->input('db_host'),
            'database' => $request->input('db_name'),
            'username' => $request->input('db_username'),
            'password' => $request->input('db_password') ?? '',
            'port'     => (int) $request->input('db_port'),
        ];

        try {
            $pdo = $this->attemptServerConnection($creds);

            $dbName    = $creds['database'];
            $charset   = config('database.connections.central.charset', 'utf8mb4');
            $collation = config('database.connections.central.collation', 'utf8mb4_unicode_ci');

            $pdo->exec("CREATE DATABASE `{$dbName}` CHARACTER SET {$charset} COLLATE {$collation}");

            return response()->json(['success' => true, 'message' => "Database \"{$dbName}\" created successfully."]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => "Failed to create database: {$e->getMessage()}"]);
        }
    }

    /**
     * Revert asset URL after a failed $tenant->run() leaves tenancy partially initialized.
     * When run() throws, the FilesystemTenancyBootstrapper revert() never fires,
     * so asset() keeps producing /tenancy/assets/ URLs. This restores the original state.
     */
    protected function revertTenancyAssets(): void
    {
        config(['app.asset_url' => null]);
        app('url')->setAssetRoot(null);
    }

    /**
     * Attempt a direct PDO connection to verify credentials.
     *
     * @throws \PDOException
     */
    protected function attemptDatabaseConnection(array $creds): void
    {
        $dsn = "mysql:host={$creds['host']};port={$creds['port']};dbname={$creds['database']}";

        $pdo = new \PDO($dsn, $creds['username'], $creds['password'], [
            \PDO::ATTR_TIMEOUT            => 5,
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8mb4'",
        ]);

        // Verify we can actually query
        $pdo->query('SELECT 1');
    }

    /**
     * Connect to the MySQL server without specifying a database (to test credentials only).
     *
     * @throws \PDOException
     */
    protected function attemptServerConnection(array $creds): \PDO
    {
        $dsn = "mysql:host={$creds['host']};port={$creds['port']}";

        $pdo = new \PDO($dsn, $creds['username'], $creds['password'], [
            \PDO::ATTR_TIMEOUT            => 5,
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8mb4'",
        ]);

        $pdo->query('SELECT 1');

        return $pdo;
    }

    /**
     * Update database credentials for an already-provisioned tenant.
     */
    public function updateCredentials(Request $request, Tenant $tenant): RedirectResponse
    {
        $request->validate([
            'db_host'     => ['required', 'string', 'max:255'],
            'db_name'     => ['required', 'string', 'max:255'],
            'db_username' => ['required', 'string', 'max:255'],
            'db_password' => ['nullable', 'string', 'max:255'],
            'db_port'     => ['required', 'integer', 'min:1', 'max:65535'],
        ]);

        // Save encrypted credentials
        $tenant->setDatabaseCredentials(
            $request->input('db_host'),
            $request->input('db_name'),
            $request->input('db_username'),
            $request->input('db_password') ?? '',
            (int) $request->input('db_port')
        );

        // Test connection
        try {
            $this->attemptDatabaseConnection($tenant->getDatabaseCredentials());
        } catch (\Throwable $e) {
            return back()->with('error', "Credentials saved but connection failed: {$e->getMessage()}")
                         ->withInput();
        }

        return back()->with('success', 'Database credentials updated and connection verified.');
    }

    /**
     * Re-provision: wipe DB, re-run migrations, re-seed, re-create admin, re-install passport, reconfigure company, copy images.
     */
    public function reprovision(Tenant $tenant): RedirectResponse
    {
        // Use effective credentials so this works for BOTH admin-provided
        // (shared-hosting) and auto-provisioned (VPS) tenants. hasDatabaseCredentials()
        // is only true for the former, which would otherwise block re-provisioning
        // a failed auto-provisioned tenant whose DB the job creates itself.
        $creds = $tenant->getEffectiveDatabaseCredentials();
        if (empty($creds['database'])) {
            return back()->with('error', 'No database configured for this tenant.');
        }

        try {
            $this->attemptDatabaseConnection($creds);
        } catch (\Throwable $e) {
            // The database may not exist yet (e.g. provisioning failed before
            // creation) — that's fine, the provisioning job creates it. Only
            // surface an error if we cannot reach the database server at all.
            try {
                $this->attemptServerConnection($creds);
            } catch (\Throwable $serverError) {
                return back()->with('error', "Cannot connect to tenant database server: {$serverError->getMessage()}");
            }
        }

        // Mark as provisioning now — the heavy work runs after the response is sent
        $tenant->update(['status' => Tenant::STATUS_PROVISIONING, 'provisioning_error' => null]);

        // Run provisioning after the HTTP response is sent to the browser.
        // This avoids web server gateway timeouts (nginx/apache) that kill the
        // PHP process when migrations + seeding take longer than ~60 seconds.
        $tenantId = $tenant->id;
        app()->terminating(function () use ($tenantId) {
            // Ensure the browser connection is fully closed before heavy work
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            try {
                $job = new ProvisionTenantWorkspace($tenantId, true);
                $job->handle();

                // Mirror approve(): once the workspace is live, lift the
                // subscription out of 'pending' so billing status reflects the
                // active tenant. Without this a re-provisioned tenant whose
                // first provision failed would keep showing "Pending" forever.
                $tenant = Tenant::find($tenantId);
                if ($tenant && $tenant->status === Tenant::STATUS_ACTIVE) {
                    $subscription = TenantSubscription::where('tenant_id', $tenantId)->first();
                    if ($subscription && $subscription->status === TenantSubscription::STATUS_PENDING) {
                        $isTrial = $subscription->trial_ends_at !== null;
                        $subscription->update([
                            'status' => $isTrial ? TenantSubscription::STATUS_TRIAL : TenantSubscription::STATUS_ACTIVE,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                Log::error("Reprovision failed for tenant {$tenantId}: {$e->getMessage()}");
                $tenant = Tenant::find($tenantId);
                if ($tenant && $tenant->status !== Tenant::STATUS_ACTIVE) {
                    $tenant->update(['status' => Tenant::STATUS_FAILED, 'provisioning_error' => $e->getMessage()]);
                }
            }
        });

        return back()->with('success', 'Re-provisioning started. The page will show "Provisioning" status — refresh to check when it completes.');
    }

    /**
     * Reset database: wipe all tables and re-run migrations only (no seeding).
     */
    public function resetDatabase(Tenant $tenant): RedirectResponse
    {
        if (! $tenant->hasDatabaseCredentials()) {
            return back()->with('error', 'No database credentials configured for this tenant.');
        }

        try {
            $this->attemptDatabaseConnection($tenant->getDatabaseCredentials());
        } catch (\Throwable $e) {
            return back()->with('error', "Cannot connect to tenant database: {$e->getMessage()}");
        }

        try {
            $tenant->run(function () {
                Artisan::call('db:wipe', ['--force' => true]);

                Artisan::call('migrate', [
                    '--path'     => [database_path('migrations/tenant')],
                    '--realpath' => true,
                    '--force'    => true,
                ]);

                Artisan::call('migrate', [
                    '--path'  => 'vendor/laravel/passport/database/migrations',
                    '--force' => true,
                ]);
            });

            return back()->with('success', 'Database reset successfully. All tables dropped and migrations re-run.');
        } catch (\Throwable $e) {
            return back()->with('error', "Database reset failed: {$e->getMessage()}");
        }
    }

    /**
     * Re-seed: run all seeders + translations on tenant database.
     */
    public function reseed(Tenant $tenant): RedirectResponse
    {
        if (! $tenant->hasDatabaseCredentials()) {
            return back()->with('error', 'No database credentials configured for this tenant.');
        }

        try {
            $this->attemptDatabaseConnection($tenant->getDatabaseCredentials());
        } catch (\Throwable $e) {
            return back()->with('error', "Cannot connect to tenant database: {$e->getMessage()}");
        }

        try {
            $tenant->run(function () {
                Artisan::call('db:seed', [
                    '--class' => 'DatabaseSeeder',
                    '--force' => true,
                ]);

                Artisan::call('db:seed', [
                    '--class' => 'Database\\Seeders\\TranslationSeeder',
                    '--force' => true,
                ]);
            });

            // Copy default images
            $source = public_path('images/tenant-default');
            $destination = public_path('images/tenants/' . $tenant->id);
            if (File::isDirectory($source)) {
                File::copyDirectory($source, $destination);

                // Fall back to the shared /pwa_images icons when the template
                // ships no PWA icons of its own, so the tenant folder always
                // has a valid 192/512 pair.
                if (! File::isDirectory($source . DIRECTORY_SEPARATOR . 'pwa')
                    && File::isDirectory(public_path('pwa_images'))) {
                    $destPwa = $destination . DIRECTORY_SEPARATOR . 'pwa';
                    if (! File::isDirectory($destPwa)) {
                        File::makeDirectory($destPwa, 0755, true);
                    }
                    foreach (['pwa-icon-192.png', 'pwa-icon-512.png'] as $icon) {
                        $iconSrc = public_path('pwa_images') . DIRECTORY_SEPARATOR . $icon;
                        if (is_file($iconSrc)) {
                            @copy($iconSrc, $destPwa . DIRECTORY_SEPARATOR . $icon);
                        }
                    }
                }
            }

            return back()->with('success', 'Database re-seeded and default images restored successfully.');
        } catch (\Throwable $e) {
            return back()->with('error', "Re-seeding failed: {$e->getMessage()}");
        }
    }

    /**
     * Permanently remove a tenant: drops database, deletes storage/images dirs,
     * removes subscriptions, payments, domains, and pending registrations.
     */
    public function destroy(Tenant $tenant): RedirectResponse
    {
        $tenantId = $tenant->id;

        // 1) Drop tenant database (best-effort — do not block deletion if DB is unreachable)
        try {
            $creds = $tenant->getEffectiveDatabaseCredentials();
            if (! empty($creds['database'])) {
                $pdo = $this->attemptServerConnection($creds);
                $dbName = $creds['database'];
                $pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to drop tenant database for {$tenantId}: {$e->getMessage()}");
        }

        // 2) Delete tenant storage directory
        try {
            $storageDir = storage_path('tenant' . $tenantId);
            if (File::isDirectory($storageDir)) {
                File::deleteDirectory($storageDir);
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to delete tenant storage for {$tenantId}: {$e->getMessage()}");
        }

        // 3) Delete tenant images directory
        try {
            $imagesDir = public_path('images/tenants/' . $tenantId);
            if (File::isDirectory($imagesDir)) {
                File::deleteDirectory($imagesDir);
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to delete tenant images for {$tenantId}: {$e->getMessage()}");
        }

        // 4) Delete related central-database records
        try {
            PendingRegistration::where('tenant_id', $tenantId)->delete();
            TenantBillingPayment::where('tenant_id', $tenantId)->delete();
            TenantSubscription::where('tenant_id', $tenantId)->delete();
            $tenant->domains()->delete();
        } catch (\Throwable $e) {
            Log::warning("Failed to delete related records for tenant {$tenantId}: {$e->getMessage()}");
        }

        // 5) Delete the tenant row. Stancl fires TenantDeleted → DeleteDatabase job
        // which tries to drop the DB we already dropped; swallow any error from that.
        try {
            $tenant->delete();
        } catch (\Throwable $e) {
            Log::warning("Tenant row deletion raised exception for {$tenantId}: {$e->getMessage()}");
            DB::connection('central')->table('tenants')->where('id', $tenantId)->delete();
        }

        return redirect()->route('super.tenants.index')
            ->with('success', __('super.tenants.delete_success'));
    }

    public function reject(Request $request, Tenant $tenant): RedirectResponse
    {
        $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if (! $tenant->isPending()) {
            return back()->with('error', 'This tenant is not in pending status.');
        }

        $adminEmail = $tenant->admin_email;
        $companyName = $tenant->company_name ?? 'Your Company';
        $tenantLocale = $tenant->locale ?? null;

        // Mark tenant as rejected and store the reason
        $tenant->status = Tenant::STATUS_REJECTED;
        $tenant->rejection_reason = $request->input('reason');
        $tenant->save();

        // Send rejection email
        try {
            $settings = GeneralSetting::instance();
            $appName = $settings->app_name ?? config('app.name');

            Mail::to($adminEmail)->send(new TenantRejectedMail(
                $companyName,
                $request->input('reason'),
                $appName,
                $tenantLocale
            ));
        } catch (\Throwable $e) {
            Log::warning("Failed to send tenant rejection email: {$e->getMessage()}");
        }

        return back()->with('success', 'Tenant rejected and notified.');
    }
}
