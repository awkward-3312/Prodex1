<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Central\PaddleCheckoutAttempt;
use App\Models\Central\PaddleSubscription;
use App\Models\Central\Plan;
use App\Services\Paddle\PaddleCheckoutReference;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class PaddleBillingController extends Controller
{
    public function prepare(Request $request, PaddleCheckoutReference $references): JsonResponse
    {
        $this->authorizeBilling();

        $validated = $request->validate([
            'plan_id' => ['required', 'integer'],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
        ]);

        $tenant = tenant();
        if (! $tenant) {
            return response()->json(['success' => false, 'message' => 'No se pudo identificar el tenant.'], 422);
        }

        $plan = Plan::where('is_active', true)->find($validated['plan_id']);
        if (! $plan) {
            return response()->json(['success' => false, 'message' => 'El plan seleccionado no está disponible.'], 422);
        }

        // Paddle is intentionally limited to Emprendedor until the rest of the
        // Paddle catalog has real Price IDs configured in Super Admin.
        if ((int) $plan->id !== 1 || $plan->slug !== 'starter') {
            return response()->json(['success' => false, 'message' => 'Paddle todavía no está configurado para este plan.'], 422);
        }

        $environment = strtolower((string) config('services.paddle.environment', 'sandbox'));
        if (! in_array($environment, ['sandbox', 'live'], true)) {
            return response()->json(['success' => false, 'message' => 'El ambiente de Paddle no es válido.'], 422);
        }

        if ($environment === 'sandbox') {
            $sandboxTenant = trim((string) config('services.paddle.sandbox_tenant', ''));
            if ($sandboxTenant === '' || (string) $tenant->getTenantKey() !== $sandboxTenant) {
                return response()->json(['success' => false, 'message' => 'Paddle Sandbox no está habilitado para este tenant.'], 422);
            }
        }

        $cycle = $validated['billing_cycle'];
        $priceId = trim((string) config(
            $cycle === 'yearly'
                ? 'services.paddle.starter_yearly_price_id'
                : 'services.paddle.starter_monthly_price_id',
            ''
        ));
        $clientToken = trim((string) config('services.paddle.client_side_token', ''));

        if ($priceId === '' || $clientToken === '') {
            return response()->json(['success' => false, 'message' => 'Paddle no tiene todas sus credenciales o precios configurados.'], 422);
        }

        $activePaddle = PaddleSubscription::with('subscription')
            ->where('tenant_id', (string) $tenant->getTenantKey())
            ->whereIn('status', ['trialing', 'active', 'past_due', 'paused'])
            ->latest()
            ->first();

        if ($activePaddle && (int) ($activePaddle->subscription?->plan_id ?? 0) === (int) $plan->id) {
            return response()->json([
                'success' => false,
                'message' => 'Este tenant ya tiene una suscripción de Paddle para el plan seleccionado.',
            ], 409);
        }

        $tenantKey = (string) $tenant->getTenantKey();

        // Serializes double-clicks, page refreshes, and parallel requests
        // for the same tenant+plan+cycle so they can never each create their
        // own valid 'initiated' attempt — reusing whichever one already
        // exists and is still claimable instead. Scoped narrower than
        // 'billing:cancel:{subscription id}' (no subscription exists yet at
        // this point) and holds no external I/O, so a short wait/hold budget
        // is enough.
        $lockKey = "paddle:prepare:{$tenantKey}:{$plan->id}:{$cycle}";
        $lock = Cache::lock($lockKey, 10);

        try {
            $attempt = $lock->block(5, function () use ($tenantKey, $plan, $cycle) {
                PaddleCheckoutAttempt::where('tenant_id', $tenantKey)
                    ->where('status', 'initiated')
                    ->where('expires_at', '<=', now())
                    ->update(['status' => 'expired']);

                // Reuse a still-claimable attempt for this exact
                // tenant+plan+cycle rather than minting a second valid one —
                // claimed/expired/rejected rows never match (status must be
                // 'initiated' and expires_at must still be in the future).
                $existing = PaddleCheckoutAttempt::where('tenant_id', $tenantKey)
                    ->where('plan_id', (int) $plan->id)
                    ->where('billing_cycle', $cycle)
                    ->where('status', 'initiated')
                    ->where('expires_at', '>', now())
                    ->latest()
                    ->first();

                if ($existing) {
                    return $existing;
                }

                return PaddleCheckoutAttempt::create([
                    'reference' => Str::uuid()->toString(),
                    'tenant_id' => $tenantKey,
                    'plan_id' => (int) $plan->id,
                    'billing_cycle' => $cycle,
                    'status' => 'initiated',
                    'expires_at' => now()->addDay(),
                ]);
            });
        } catch (LockTimeoutException) {
            return response()->json([
                'success' => false,
                'message' => 'Ya hay una solicitud de checkout en curso. Intenta de nuevo en unos segundos.',
            ], 409);
        }

        return response()->json([
            'success' => true,
            'environment' => $environment,
            'price_id' => $priceId,
            'custom_data' => [
                'prodex_ref' => $references->issue($attempt->reference),
            ],
        ]);
    }

    private function authorizeBilling(): void
    {
        $user = auth()->user();
        if ($user && ($user->id === 1 || $this->hasBillingPermission($user))) {
            return;
        }

        abort(403, 'No estás autorizado para acceder a facturación.');
    }

    private function hasBillingPermission($user): bool
    {
        if (method_exists($user, 'hasRole') && method_exists($user, 'roles')) {
            foreach ($user->roles as $role) {
                $permissions = $role->permissions->pluck('name')->toArray();
                if (in_array('billing_view', $permissions, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
