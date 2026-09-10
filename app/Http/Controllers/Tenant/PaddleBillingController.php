<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Central\GeneralSetting;
use App\Models\Central\PaddleSubscription;
use App\Models\Central\Plan;
use App\Models\Central\TenantSubscription;
use App\Services\Paddle\PaddleCheckoutReference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        $activePaddle = PaddleSubscription::where('tenant_id', (string) $tenant->getTenantKey())
            ->whereIn('status', ['trialing', 'active', 'past_due', 'paused'])
            ->latest()
            ->first();

        if ($activePaddle && (int) $activePaddle->subscription?->plan_id === (int) $plan->id) {
            return response()->json([
                'success' => false,
                'message' => 'Este tenant ya tiene una suscripción de Paddle para el plan seleccionado.',
            ], 409);
        }

        $subscription = DB::connection('central')->transaction(function () use ($tenant, $plan, $cycle) {
            $pending = TenantSubscription::where('tenant_id', (string) $tenant->getTenantKey())
                ->where('status', TenantSubscription::STATUS_PENDING)
                ->latest()
                ->first();

            $attributes = [
                'plan_id' => $plan->id,
                'billing_cycle' => $cycle,
                'amount' => $plan->getPriceForCycle($cycle),
                'currency' => GeneralSetting::currencyCode(),
                'starts_at' => $pending?->starts_at ?? now(),
                'trial_ends_at' => null,
                'ends_at' => null,
                'cancelled_at' => null,
            ];

            if ($pending) {
                $pending->update($attributes);
                return $pending->fresh();
            }

            return TenantSubscription::create(array_merge($attributes, [
                'tenant_id' => (string) $tenant->getTenantKey(),
                'status' => TenantSubscription::STATUS_PENDING,
            ]));
        });

        $reference = $references->issue(
            (string) $tenant->getTenantKey(),
            (int) $subscription->id,
            (int) $plan->id,
            $cycle
        );

        return response()->json([
            'success' => true,
            'environment' => $environment,
            'price_id' => $priceId,
            'custom_data' => [
                'prodex_ref' => $reference,
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
