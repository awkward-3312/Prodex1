<?php

namespace Tests\Unit;

use App\Models\Central\Plan;
use App\Services\PlanRecommendationService;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Lógica de la calculadora de precios de landing-prime.
 * Fuente única de la recomendación — no se duplica en JS.
 */
class PlanRecommendationServiceTest extends TestCase
{
    private PlanRecommendationService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new PlanRecommendationService();
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function plans(array $rows): Collection
    {
        return collect($rows)->map(function (array $r, int $i) {
            $plan = new Plan(array_merge([
                'name'         => $r['name'] ?? ('Plan ' . $i),
                'slug'         => $r['slug'] ?? ('plan-' . $i),
                'price'        => $r['price'] ?? 0,
                'yearly_price' => $r['yearly_price'] ?? null,
                'limits'       => $r['limits'] ?? [],
                'features'     => $r['features'] ?? [],
                'is_active'    => true,
                'is_private'   => false,
                'is_trial'     => $r['is_trial'] ?? false,
                'trial_days'   => $r['trial_days'] ?? 0,
            ], $r));
            $plan->forceFill(['id' => $r['id'] ?? ($i + 1)]);

            return $plan;
        })->values();
    }

    private function req(int $u = 0, int $w = 0, int $c = 0, int $s = 0, int $p = 0): array
    {
        return [
            'max_users' => $u, 'max_warehouses' => $w,
            'max_customers' => $c, 'max_suppliers' => $s, 'max_products' => $p,
        ];
    }

    public function test_recommends_cheapest_plan_that_fits(): void
    {
        $plans = $this->plans([
            ['name' => 'Chico', 'price' => 10, 'limits' => ['max_users' => 2, 'max_products' => 100, 'max_customers' => 50, 'max_suppliers' => 10, 'max_warehouses' => 1]],
            ['name' => 'Medio', 'price' => 30, 'limits' => ['max_users' => 10, 'max_products' => 1000, 'max_customers' => 500, 'max_suppliers' => 50, 'max_warehouses' => 5]],
            ['name' => 'Grande', 'price' => 90, 'limits' => ['max_users' => 50, 'max_products' => 10000, 'max_customers' => 5000, 'max_suppliers' => 500, 'max_warehouses' => 25]],
        ]);

        $out = $this->svc->recommend($this->req(u: 4, w: 2, c: 200, s: 30, p: 800), 'monthly', $plans);

        $this->assertSame(PlanRecommendationService::STATUS_OK, $out['recommendation_status']);
        $this->assertSame('Medio', $out['recommended']['name']);
        $this->assertFalse($out['exceeds']);
    }

    public function test_recommends_smallest_when_everything_fits(): void
    {
        $plans = $this->plans([
            ['name' => 'Chico', 'price' => 10, 'limits' => ['max_users' => 2, 'max_products' => 100, 'max_customers' => 50, 'max_suppliers' => 10, 'max_warehouses' => 1]],
            ['name' => 'Medio', 'price' => 30, 'limits' => ['max_users' => 10, 'max_products' => 1000, 'max_customers' => 500, 'max_suppliers' => 50, 'max_warehouses' => 5]],
        ]);

        $out = $this->svc->recommend($this->req(u: 1, w: 1, c: 10, s: 2, p: 20), 'monthly', $plans);

        $this->assertSame(PlanRecommendationService::STATUS_OK, $out['recommendation_status']);
        $this->assertSame('Chico', $out['recommended']['name']);
    }

    /** CORRECCIÓN 1: clientes y proveedores NUNCA se suman. */
    public function test_customers_and_suppliers_are_independent_limits(): void
    {
        $plans = $this->plans([
            // La suma (1000 + 10 = 1010) cubriría 550, pero proveedores (10) NO cubre 50.
            ['name' => 'Trampa', 'price' => 10, 'limits' => ['max_customers' => 1000, 'max_suppliers' => 10, 'max_users' => 10, 'max_products' => 10000, 'max_warehouses' => 5]],
            ['name' => 'Correcto', 'price' => 25, 'limits' => ['max_customers' => 800, 'max_suppliers' => 100, 'max_users' => 10, 'max_products' => 10000, 'max_warehouses' => 5]],
        ]);

        $out = $this->svc->recommend($this->req(c: 500, s: 50, u: 3, p: 100, w: 1), 'monthly', $plans);

        $this->assertSame(PlanRecommendationService::STATUS_OK, $out['recommendation_status']);
        $this->assertSame('Correcto', $out['recommended']['name'], 'no debe elegir el plan por suma de contactos');
    }

    /** CORRECCIÓN 3: si ningún plan cumple => custom, nunca "recomendado". */
    public function test_no_plan_fits_returns_custom_not_biggest_as_recommended(): void
    {
        $plans = $this->plans([
            ['name' => 'Chico', 'price' => 10, 'limits' => ['max_users' => 2, 'max_products' => 100, 'max_customers' => 50, 'max_suppliers' => 10, 'max_warehouses' => 1]],
            ['name' => 'Grande', 'price' => 90, 'limits' => ['max_users' => 50, 'max_products' => 10000, 'max_customers' => 5000, 'max_suppliers' => 500, 'max_warehouses' => 25]],
        ]);

        $out = $this->svc->recommend($this->req(u: 999, w: 999, c: 999999, s: 999999, p: 999999), 'monthly', $plans);

        $this->assertSame(PlanRecommendationService::STATUS_CUSTOM, $out['recommendation_status']);
        $this->assertTrue($out['exceeds']);
        $this->assertNull($out['recommended']);
        $this->assertSame('Grande', $out['starting_point']['name']);
    }

    /** CORRECCIÓN 2: catálogo sin límites numéricos => no se fabrica recomendación. */
    public function test_catalog_without_any_numeric_limit_is_insufficient_plan_data(): void
    {
        $plans = $this->plans([
            ['name' => 'A', 'price' => 10, 'limits' => []],
            ['name' => 'B', 'price' => 30, 'limits' => []],
        ]);

        $out = $this->svc->recommend($this->req(u: 3, p: 100), 'monthly', $plans);

        $this->assertSame(PlanRecommendationService::STATUS_NO_DATA, $out['recommendation_status']);
        $this->assertNull($out['recommended']);
        $this->assertNull($out['starting_point']);
        $this->assertCount(2, $out['plans'], 'la lista de planes reales sigue disponible para el fallback');
    }

    public function test_empty_catalog_is_insufficient_plan_data(): void
    {
        $out = $this->svc->recommend($this->req(u: 1), 'monthly', collect());

        $this->assertSame(PlanRecommendationService::STATUS_NO_DATA, $out['recommendation_status']);
        $this->assertSame([], $out['plans']);
    }

    /**
     * Contrato histórico de PRODEX (documentado + reforzado por
     * TenantLimitsService::hasReachedLimit): clave ausente en `limits` => SIN TOPE.
     */
    public function test_absent_limit_key_means_unlimited_per_documented_contract(): void
    {
        $plans = $this->plans([
            // Solo declara max_users. Sin max_products => productos ilimitados.
            ['name' => 'Solo usuarios', 'price' => 20, 'limits' => ['max_users' => 5]],
        ]);

        $out = $this->svc->recommend($this->req(u: 4, p: 999999, c: 999999, s: 999999, w: 999), 'monthly', $plans);

        $this->assertSame(PlanRecommendationService::STATUS_OK, $out['recommendation_status']);
        $this->assertSame('Solo usuarios', $out['recommended']['name']);
    }

    public function test_negative_one_means_unlimited(): void
    {
        $plans = $this->plans([
            ['name' => 'Mixto', 'price' => 20, 'limits' => ['max_users' => -1, 'max_products' => 100, 'max_customers' => -1, 'max_suppliers' => -1, 'max_warehouses' => -1]],
        ]);

        $out = $this->svc->recommend($this->req(u: 9999, p: 50), 'monthly', $plans);
        $this->assertSame(PlanRecommendationService::STATUS_OK, $out['recommendation_status']);

        $out2 = $this->svc->recommend($this->req(u: 9999, p: 500), 'monthly', $plans);
        $this->assertSame(PlanRecommendationService::STATUS_CUSTOM, $out2['recommendation_status']);
    }

    public function test_zero_limit_blocks_any_positive_request(): void
    {
        $plans = $this->plans([
            ['name' => 'Cero', 'price' => 5, 'limits' => ['max_users' => 0, 'max_products' => 100]],
            ['name' => 'Uno', 'price' => 15, 'limits' => ['max_users' => 3, 'max_products' => 100]],
        ]);

        $out = $this->svc->recommend($this->req(u: 1, p: 10), 'monthly', $plans);
        $this->assertSame('Uno', $out['recommended']['name']);
    }

    /** CORRECCIÓN 5: yearly_price <= monthly_price se ignora, sin descuento ficticio. */
    public function test_inconsistent_yearly_price_is_ignored_without_fake_discount(): void
    {
        $plan = new Plan(['name' => 'X', 'slug' => 'x', 'price' => 1199, 'yearly_price' => 11.99, 'limits' => ['max_users' => 10], 'is_active' => true, 'is_private' => false]);

        $info = $this->svc->yearlyInfo($plan);
        $this->assertFalse($info['available']);
        $this->assertNull($info['price']);
        $this->assertSame(0, $info['savings']);

        $out = $this->svc->recommend($this->req(u: 2), 'yearly', collect([$plan]));
        $rec = $out['recommended'];
        $this->assertNull($rec['price_yearly']);
        $this->assertFalse($rec['yearly_available']);
        $this->assertSame(1199.0, $rec['billed_amount'], 'con anual inválido factura el mensual');
        $this->assertSame('month', $rec['billed_period']);
    }

    public function test_valid_yearly_price_produces_real_savings(): void
    {
        $plan = new Plan(['name' => 'Y', 'slug' => 'y', 'price' => 100, 'yearly_price' => 1000, 'limits' => ['max_users' => 10], 'is_active' => true, 'is_private' => false]);

        $info = $this->svc->yearlyInfo($plan);
        $this->assertTrue($info['available']);
        $this->assertSame(1000.0, $info['price']);
        $this->assertSame(17, $info['savings']); // (1200-1000)/1200

        $out = $this->svc->recommend($this->req(u: 1), 'yearly', collect([$plan]));
        $this->assertSame(1000.0, $out['recommended']['billed_amount']);
        $this->assertSame('year', $out['recommended']['billed_period']);
    }

    public function test_absurd_yearly_savings_is_rejected(): void
    {
        // 5 de 1200 => 99% de ahorro: absurdo.
        $plan = new Plan(['name' => 'Z', 'slug' => 'z', 'price' => 100, 'yearly_price' => 5, 'limits' => ['max_users' => 10], 'is_active' => true, 'is_private' => false]);
        $this->assertFalse($this->svc->yearlyInfo($plan)['available']);
    }

    public function test_register_url_carries_plan_and_billing_cycle(): void
    {
        $plans = $this->plans([
            ['name' => 'Uno', 'price' => 100, 'yearly_price' => 1000, 'limits' => ['max_users' => 10]],
        ]);

        $out = $this->svc->recommend($this->req(u: 1), 'yearly', $plans);
        $url = $out['recommended']['register_url'];
        $this->assertStringContainsString('billing_cycle=yearly', $url);
        $this->assertMatchesRegularExpression('/[?&]plan=/', $url);
    }

    public function test_free_plan_is_marked_free_and_has_no_yearly(): void
    {
        $plans = $this->plans([
            ['name' => 'Gratis', 'price' => 0, 'yearly_price' => 0, 'limits' => ['max_users' => 2, 'max_products' => 50]],
        ]);

        $out = $this->svc->recommend($this->req(u: 1, p: 10), 'yearly', $plans);
        $rec = $out['recommended'];
        $this->assertTrue($rec['is_free']);
        $this->assertFalse($rec['yearly_available']);
        $this->assertSame(0.0, $rec['billed_amount']);
    }

    public function test_recommended_detail_includes_limits_and_features(): void
    {
        $plans = $this->plans([
            ['name' => 'Pro', 'price' => 50, 'limits' => ['max_users' => 10, 'max_products' => 500], 'features' => ['pos', 'transfers']],
        ]);

        $out = $this->svc->recommend($this->req(u: 2, p: 100), 'monthly', $plans);
        $rec = $out['recommended'];

        $this->assertNotEmpty($rec['included']);
        $users = collect($rec['included'])->firstWhere('key', 'max_users');
        $this->assertSame('10', $users['display']);
        $this->assertFalse($users['unlimited']);
        $products = collect($rec['included'])->firstWhere('key', 'max_products');
        $this->assertSame('500', $products['display']);
        // max_warehouses ausente => ilimitado en el detalle.
        $wh = collect($rec['included'])->firstWhere('key', 'max_warehouses');
        $this->assertTrue($wh['unlimited']);

        $this->assertContains(Plan::AVAILABLE_FEATURES['pos']['label'], $rec['features']);
        $this->assertContains(Plan::AVAILABLE_FEATURES['transfers']['label'], $rec['features']);
    }

    /** La sección de comparación (siempre visible) necesita el detalle en cada plan. */
    public function test_plans_array_carries_included_and_features_for_the_comparison_section(): void
    {
        $plans = $this->plans([
            ['name' => 'Chico', 'price' => 10, 'limits' => ['max_users' => 2, 'max_products' => 100], 'features' => ['pos']],
            ['name' => 'Grande', 'price' => 40, 'limits' => ['max_users' => 20, 'max_products' => 5000], 'features' => ['pos', 'hrm']],
        ]);

        $out = $this->svc->recommend($this->req(u: 1, p: 10), 'monthly', $plans);

        foreach ($out['plans'] as $row) {
            $this->assertArrayHasKey('included', $row, 'cada plan de la lista lleva límites');
            $this->assertArrayHasKey('features', $row, 'cada plan de la lista lleva features');
            $this->assertArrayHasKey('register_url', $row);
        }
        $chico = collect($out['plans'])->firstWhere('name', 'Chico');
        $this->assertSame('2', collect($chico['included'])->firstWhere('key', 'max_users')['display']);
        $this->assertContains(Plan::AVAILABLE_FEATURES['pos']['label'], $chico['features']);
    }

    public function test_db_backed_recommendation_uses_public_scope(): void
    {
        Plan::query()->getConnection()->table('plans')->insert([
            ['name' => 'DB Chico', 'slug' => 'db-chico', 'price' => 10, 'yearly_price' => null, 'billing_interval' => 'monthly', 'limits' => json_encode(['max_users' => 2, 'max_products' => 100, 'max_customers' => 50, 'max_suppliers' => 10, 'max_warehouses' => 1]), 'features' => json_encode(['pos']), 'is_active' => 1, 'is_private' => 0, 'is_trial' => 0, 'trial_days' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'DB Grande', 'slug' => 'db-grande', 'price' => 80, 'yearly_price' => 800, 'billing_interval' => 'monthly', 'limits' => json_encode(['max_users' => 40, 'max_products' => 8000, 'max_customers' => 4000, 'max_suppliers' => 400, 'max_warehouses' => 20]), 'features' => json_encode(['pos', 'hrm']), 'is_active' => 1, 'is_private' => 0, 'is_trial' => 1, 'trial_days' => 14, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'DB Privado', 'slug' => 'db-privado', 'price' => 5, 'yearly_price' => null, 'billing_interval' => 'monthly', 'limits' => json_encode([]), 'features' => json_encode([]), 'is_active' => 1, 'is_private' => 1, 'is_trial' => 0, 'trial_days' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $out = $this->svc->recommend($this->req(u: 10, w: 3, c: 200, s: 30, p: 900), 'monthly');

        $this->assertSame(PlanRecommendationService::STATUS_OK, $out['recommendation_status']);
        $this->assertSame('DB Grande', $out['recommended']['name']);
        $this->assertCount(2, $out['plans'], 'el plan privado no aparece');
    }
}
