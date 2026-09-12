<?php

namespace Tests\Feature;

use App\Http\Controllers\Mobile\MobileReportsSummaryController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\MobileClientsReportsTestSchema;
use Tests\TestCase;

class MobileReportsSummaryEndpointTest extends TestCase
{
    use MobileClientsReportsTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMobileClientsReportsSchema();
        Route::middleware('auth:api')->get('/api/mobile/reports-test/summary', MobileReportsSummaryController::class);
    }

    private function url(string $from = '2026-05-01', string $to = '2026-05-31'): string
    {
        return "/api/mobile/reports-test/summary?from={$from}&to={$to}";
    }

    public function test_requires_authentication(): void
    {
        $this->getJson($this->url())->assertStatus(401);
    }

    public function test_denies_user_without_reports_sales_permission(): void
    {
        $user = $this->user(['role_id' => 2], []);
        $this->actingAs($user, 'api')->getJson($this->url())->assertStatus(403);
    }

    public function test_allows_user_with_reports_sales_permission(): void
    {
        $user = $this->user(['role_id' => 2], ['Reports_sales']);
        $this->actingAs($user, 'api')->getJson($this->url())->assertStatus(200);
    }

    public function test_requires_from_and_to_date_range(): void
    {
        $user = $this->user(['role_id' => 1], ['Reports_sales']);
        $this->actingAs($user, 'api')
            ->getJson('/api/mobile/reports-test/summary')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');
    }

    public function test_computes_correct_totals_average_tax_paid_and_pending(): void
    {
        $owner = $this->user(['role_id' => 1], ['Reports_sales']);
        $this->sale(['date' => '2026-05-10', 'GrandTotal' => 100, 'paid_amount' => 100, 'TaxNet' => 15]);
        $this->sale(['date' => '2026-05-15', 'GrandTotal' => 200, 'paid_amount' => 150, 'TaxNet' => 30]);
        $this->sale(['date' => '2026-01-01', 'GrandTotal' => 999, 'paid_amount' => 999, 'TaxNet' => 0]); // outside range

        $response = $this->actingAs($owner, 'api')->getJson($this->url())->assertStatus(200);
        $data = $response->json('data');

        $this->assertSame(2, $data['sales_count']);
        $this->assertSame('300.00', $data['sales_total']);
        $this->assertSame('150.00', $data['average_sale']);
        $this->assertSame('45.00', $data['tax_total']);
        $this->assertSame('250.00', $data['paid_total']);
        $this->assertSame('50.00', $data['pending_total']);
    }

    public function test_excludes_non_completed_sales(): void
    {
        $owner = $this->user(['role_id' => 1], ['Reports_sales']);
        $this->sale(['date' => '2026-05-10', 'GrandTotal' => 500, 'paid_amount' => 500, 'statut' => 'draft']);

        $response = $this->actingAs($owner, 'api')->getJson($this->url())->assertStatus(200);
        $this->assertSame(0, $response->json('data.sales_count'));
    }

    public function test_top_products_and_top_customers_are_correctly_ranked(): void
    {
        $owner = $this->user(['role_id' => 1], ['Reports_sales']);
        $clientA = $this->client(['name' => 'Cliente A']);
        $clientB = $this->client(['name' => 'Cliente B']);
        $productId = DB::table('products')->insertGetId(['name' => 'Producto Estrella', 'created_at' => now(), 'updated_at' => now()]);

        $saleA = $this->sale(['date' => '2026-05-10', 'client_id' => $clientA->id, 'GrandTotal' => 500, 'paid_amount' => 500]);
        $saleB = $this->sale(['date' => '2026-05-11', 'client_id' => $clientB->id, 'GrandTotal' => 100, 'paid_amount' => 100]);
        $this->saleDetail($saleA->id, ['product_id' => $productId, 'quantity' => 10]);
        $this->saleDetail($saleB->id, ['product_id' => $productId, 'quantity' => 2]);

        $response = $this->actingAs($owner, 'api')->getJson($this->url())->assertStatus(200);

        $this->assertSame('Cliente A', $response->json('data.top_customers.0.name'));
        $this->assertSame('12.000', $response->json('data.top_products.0.quantity'));
    }

    public function test_payment_method_breakdown_sums_correctly(): void
    {
        $owner = $this->user(['role_id' => 1], ['Reports_sales']);
        $cashId = DB::table('payment_methods')->insertGetId(['name' => 'Efectivo', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $cardId = DB::table('payment_methods')->insertGetId(['name' => 'Tarjeta', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $sale = $this->sale(['date' => '2026-05-10', 'GrandTotal' => 300, 'paid_amount' => 300]);
        $this->paymentSale($sale->id, ['payment_method_id' => $cashId, 'montant' => 100]);
        $this->paymentSale($sale->id, ['payment_method_id' => $cardId, 'montant' => 200]);

        $response = $this->actingAs($owner, 'api')->getJson($this->url())->assertStatus(200);
        $methods = collect($response->json('data.payment_methods'))->keyBy('name');
        $this->assertSame('100.00', $methods['Efectivo']['total']);
        $this->assertSame('200.00', $methods['Tarjeta']['total']);
    }

    public function test_non_owner_only_sees_totals_within_their_branch_never_tenant_wide_figures(): void
    {
        $branchA = $this->branch();
        $branchB = $this->branch();
        $user = $this->user(['role_id' => 2], ['Reports_sales'], branchIds: [$branchA->id]);
        $this->sale(['date' => '2026-05-10', 'branch_id' => $branchA->id, 'GrandTotal' => 100, 'paid_amount' => 100]);
        $this->sale(['date' => '2026-05-10', 'branch_id' => $branchB->id, 'GrandTotal' => 900, 'paid_amount' => 900]);

        $response = $this->actingAs($user, 'api')->getJson($this->url())->assertStatus(200);
        $this->assertSame('100.00', $response->json('data.sales_total'));
        $this->assertSame(1, $response->json('data.sales_count'));
    }

    public function test_owner_sees_tenant_wide_totals_across_branches(): void
    {
        $branchA = $this->branch();
        $branchB = $this->branch();
        $owner = $this->user(['role_id' => 1], ['Reports_sales']);
        $this->sale(['date' => '2026-05-10', 'branch_id' => $branchA->id, 'GrandTotal' => 100, 'paid_amount' => 100]);
        $this->sale(['date' => '2026-05-10', 'branch_id' => $branchB->id, 'GrandTotal' => 900, 'paid_amount' => 900]);

        $response = $this->actingAs($owner, 'api')->getJson($this->url())->assertStatus(200);
        $this->assertSame('1000.00', $response->json('data.sales_total'));
    }

    public function test_endpoint_never_mutates_any_sale(): void
    {
        $owner = $this->user(['role_id' => 1], ['Reports_sales']);
        $sale = $this->sale(['date' => '2026-05-10']);
        $before = (array) DB::table('sales')->where('id', $sale->id)->first();

        $this->actingAs($owner, 'api')->getJson($this->url())->assertStatus(200);
        $after = (array) DB::table('sales')->where('id', $sale->id)->first();

        $this->assertSame($before, $after);
    }
}
