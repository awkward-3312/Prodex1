<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Central\GeneralSetting;
use App\Models\Central\Plan;
use Illuminate\Support\Collection;

/**
 * Fuente ÚNICA de la lógica de recomendación de plan para la calculadora de
 * precios de la landing pública (landing-prime). La misma llamada alimenta el
 * render server-side del Blade y el endpoint JSON que consume el front al mover
 * los sliders — la lógica NO se duplica en JavaScript.
 *
 * Principios (correcciones del brief):
 *  - Clientes y proveedores son límites INDEPENDIENTES: nunca se suman.
 *  - No se inventan precios ni descuentos: todo sale de `Plan::public()`
 *    (tabla `plans`, editable en /super/plans).
 *  - Fail-safe: si el catálogo público no expone NINGÚN límite numérico en las
 *    dimensiones de la calculadora, NO se fabrica una recomendación
 *    (`recommendation_status = insufficient_plan_data`).
 *  - Si ningún plan cumple, se devuelve `custom` + `exceeds` (el plan mayor se
 *    ofrece SOLO como "punto de partida", jamás como "plan recomendado").
 *
 * Contrato de límites — documentado en Plan::AVAILABLE_LIMITS y REFORZADO por el
 * runtime en TenantLimitsService::hasReachedLimit():
 *
 *     $max = $plan->getLimit($key);   // -1 cuando la clave falta / '' / null
 *     if ($max < 0) { return false; } // nunca alcanza el tope => ILIMITADO
 *
 * Por tanto, de forma inequívoca:
 *   - clave ausente en `limits`  => SIN TOPE
 *   - '' , null , o entero < 0   => SIN TOPE
 *   - entero >= 0                => tope exacto (0 = no permite nada)
 *
 * Cubierto por PlanRecommendationServiceTest.
 */
class PlanRecommendationService
{
    /** Dimensiones configurables por el usuario en la calculadora. */
    public const DIMENSIONS = [
        'max_users',
        'max_warehouses',
        'max_customers',
        'max_suppliers',
        'max_products',
    ];

    /** Dimensiones que además se muestran en el detalle del plan (incluye WhatsApp). */
    public const DETAIL_LIMITS = [
        'max_users',
        'max_warehouses',
        'max_customers',
        'max_suppliers',
        'max_products',
        'max_whatsapp_messages',
    ];

    public const STATUS_OK       = 'ok';
    public const STATUS_CUSTOM   = 'custom';
    public const STATUS_NO_DATA  = 'insufficient_plan_data';

    /**
     * @param  array<string,int|string|null>  $request  cantidades pedidas por dimensión
     * @param  string  $cycle  'monthly' | 'yearly'
     * @param  Collection<int,Plan>|null  $plans  inyectable para tests; null => Plan::public()
     * @param  array{sales_url?:string|null,register_base_url?:string|null}  $context
     * @return array<string,mixed>
     */
    public function recommend(array $request, string $cycle = 'monthly', ?Collection $plans = null, array $context = []): array
    {
        $cycle = $cycle === 'yearly' ? 'yearly' : 'monthly';
        $plans = ($plans ?? $this->defaultPlans())->values();

        $req = [];
        foreach (self::DIMENSIONS as $key) {
            $req[$key] = max(0, (int) ($request[$key] ?? 0));
        }

        $base = [
            'recommendation_status' => self::STATUS_NO_DATA,
            'cycle'                 => $cycle,
            'currency_symbol'       => $this->currencySymbol(),
            'request'               => $req,
            'recommended'           => null,
            'starting_point'        => null,
            'exceeds'               => false,
            // `plans` alimenta la sección de comparación de planes, que es SIEMPRE
            // visible: cada plan lleva su detalle real (límites + features).
            'plans'                 => $plans->map(fn (Plan $p) => $this->planPayload($p, $cycle, true))->all(),
            'sales_url'             => $context['sales_url'] ?? null,
            'register_base_url'     => $context['register_base_url'] ?? $this->registerUrl(null, $cycle),
        ];

        // Fail-safe: catálogo vacío o sin ningún límite numérico cargado.
        if ($plans->isEmpty() || ! $this->catalogHasLimitData($plans)) {
            return $base;
        }

        // Planes ya ordenados por precio ascendente: el primero que cumple es el más barato.
        foreach ($plans as $plan) {
            if ($this->planSatisfies($plan, $req)) {
                return array_merge($base, [
                    'recommendation_status' => self::STATUS_OK,
                    'recommended'           => $this->planPayload($plan, $cycle, true),
                ]);
            }
        }

        // Ninguno cumple: configuración personalizada. El plan mayor es solo punto de partida.
        return array_merge($base, [
            'recommendation_status' => self::STATUS_CUSTOM,
            'exceeds'               => true,
            'starting_point'        => $this->planPayload($plans->last(), $cycle, true),
        ]);
    }

    /** @return Collection<int,Plan> */
    protected function defaultPlans(): Collection
    {
        return Plan::public()->orderBy('price')->orderBy('id')->get();
    }

    protected function currencySymbol(): string
    {
        try {
            return GeneralSetting::currencySymbol();
        } catch (\Throwable) {
            return 'L';
        }
    }

    /**
     * ¿El catálogo público expone al menos un tope numérico en alguna dimensión
     * de la calculadora? Si no, no se puede DEMOSTRAR una recomendación con datos.
     *
     * @param  Collection<int,Plan>  $plans
     */
    protected function catalogHasLimitData(Collection $plans): bool
    {
        foreach ($plans as $plan) {
            foreach (self::DIMENSIONS as $key) {
                if ($this->cap($plan, $key) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Tope efectivo de un plan para una dimensión.
     * null => SIN TOPE (clave ausente, '', null o entero < 0). Entero >= 0 => tope.
     */
    protected function cap(Plan $plan, string $key): ?int
    {
        $limits = $plan->limits ?? [];

        if (! is_array($limits) || ! array_key_exists($key, $limits)) {
            return null;
        }

        $value = $limits[$key];
        if ($value === '' || $value === null) {
            return null;
        }

        $int = (int) $value;

        return $int < 0 ? null : $int;
    }

    /** @param  array<string,int>  $req */
    protected function planSatisfies(Plan $plan, array $req): bool
    {
        foreach (self::DIMENSIONS as $key) {
            $cap = $this->cap($plan, $key);
            if ($cap !== null && $req[$key] > $cap) {
                return false;
            }
        }

        return true;
    }

    /**
     * Protección de `yearly_price` inconsistente (p. ej. Profesional = 11.99):
     * el anual solo es válido si (0 < anual) y (anual > mensual) y
     * (anual < mensual*12) y el ahorro resultante es plausible (1..89 %).
     * En cualquier otro caso se ignora el anual: se muestra solo mensual, sin
     * descuento ficticio, sin tocar la BD. Se reporta como deuda de datos.
     *
     * @return array{available:bool,price:float|null,savings:int}
     */
    public function yearlyInfo(Plan $plan): array
    {
        $none = ['available' => false, 'price' => null, 'savings' => 0];

        $monthly = (float) $plan->price;
        $yearlyRaw = $plan->yearly_price;

        if ($monthly <= 0 || $yearlyRaw === null || $yearlyRaw === '') {
            return $none;
        }

        $yearly = (float) $yearlyRaw;
        $full = $monthly * 12;

        if ($yearly <= $monthly || $yearly >= $full) {
            return $none;
        }

        $savings = (int) round((($full - $yearly) / $full) * 100);
        if ($savings < 1 || $savings > 89) {
            return $none;
        }

        return ['available' => true, 'price' => round($yearly, 2), 'savings' => $savings];
    }

    /**
     * @return array<string,mixed>
     */
    protected function planPayload(Plan $plan, string $cycle, bool $withDetail): array
    {
        $monthly = round((float) $plan->price, 2);
        $yearly = $this->yearlyInfo($plan);
        $isFree = $plan->isFree();
        $isTrial = $plan->hasTrial();
        $useYearly = $cycle === 'yearly' && $yearly['available'];

        $payload = [
            'id'                     => $plan->id,
            'name'                   => $plan->name,
            'slug'                   => $plan->slug,
            'is_free'                => $isFree,
            'is_trial'               => $isTrial,
            'trial_days'             => $isTrial ? $plan->getTrialDays() : 0,
            'price_monthly'          => $monthly,
            'price_yearly'           => $yearly['available'] ? $yearly['price'] : null,
            'yearly_available'       => $yearly['available'],
            'yearly_savings_percent' => $yearly['savings'],
            'billed_amount'          => $useYearly ? $yearly['price'] : $monthly,
            'billed_period'          => $useYearly ? 'year' : 'month',
            'register_url'           => $this->registerUrl($plan->id, $useYearly ? 'yearly' : 'monthly'),
        ];

        if ($withDetail) {
            $payload['included'] = $this->includedLimits($plan);
            $payload['features'] = $this->featureLabels($plan);
        }

        return $payload;
    }

    /**
     * @return list<array{key:string,label:string,value:int|null,display:string,unlimited:bool}>
     */
    protected function includedLimits(Plan $plan): array
    {
        $out = [];
        foreach (self::DETAIL_LIMITS as $key) {
            $meta = Plan::AVAILABLE_LIMITS[$key] ?? null;
            if (! $meta) {
                continue;
            }
            $cap = $this->cap($plan, $key);
            $out[] = [
                'key'       => $key,
                'label'     => $meta['label'],
                'value'     => $cap,
                'display'   => $cap === null ? '∞' : number_format($cap),
                'unlimited' => $cap === null,
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    protected function featureLabels(Plan $plan): array
    {
        return array_values(array_map(
            static fn (array $meta): string => $meta['label'],
            $plan->getActiveFeatures()
        ));
    }

    protected function registerUrl(?int $planId, string $cycle): string
    {
        $params = [];
        if ($planId) {
            $params['plan'] = $planId;
        }
        $params['billing_cycle'] = $cycle === 'yearly' ? 'yearly' : 'monthly';

        try {
            return route('central.register', $params);
        } catch (\Throwable) {
            return url('/register') . '?' . http_build_query($params);
        }
    }
}
