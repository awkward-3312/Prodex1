<?php

namespace Tests\Feature;

use App\Http\Controllers\Mobile\MobileDashboardSummaryController;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\MobileClientsReportsTestSchema;
use Tests\TestCase;

class MobileDashboardSummaryEndpointTest extends TestCase
{
    use MobileClientsReportsTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMobileClientsReportsSchema();
        Route::middleware('auth:api')->get('/api/mobile/dashboard-test/summary', MobileDashboardSummaryController::class);
        Carbon::setTestNow(Carbon::parse('2026-05-15 12:00:00')); // a Friday
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/mobile/dashboard-test/summary')->assertStatus(401);
    }

    public function test_denies_user_without_sales_view_permission(): void
    {
        $user = $this->user(['role_id' => 2], []);
        $this->actingAs($user, 'api')->getJson('/api/mobile/dashboard-test/summary')->assertStatus(403);
    }

    public function test_computes_todays_totals_from_real_completed_sales(): void
    {
        $owner = $this->user(['role_id' => 1], ['Sales_view']);
        $this->sale(['date' => '2026-05-15', 'GrandTotal' => 100]);
        $this->sale(['date' => '2026-05-15', 'GrandTotal' => 200]);
        $this->sale(['date' => '2026-05-14', 'GrandTotal' => 999]); // yesterday, not today

        $response = $this->actingAs($owner, 'api')->getJson('/api/mobile/dashboard-test/summary')->assertStatus(200);
        $today = $response->json('data.today');
        $this->assertSame('300.00', $today['sales_total']);
        $this->assertSame(2, $today['sales_count']);
        $this->assertSame('150.00', $today['average_sale']);
    }

    public function test_computes_a_real_delta_against_yesterday(): void
    {
        $owner = $this->user(['role_id' => 1], ['Sales_view']);
        $this->sale(['date' => '2026-05-15', 'GrandTotal' => 150]); // today
        $this->sale(['date' => '2026-05-14', 'GrandTotal' => 100]); // yesterday

        $response = $this->actingAs($owner, 'api')->getJson('/api/mobile/dashboard-test/summary')->assertStatus(200);
        // (150 - 100) / 100 * 100 = 50.0
        $this->assertEquals(50.0, $response->json('data.today.sales_total_delta_pct'));
        $this->assertEquals(50.0, $response->json('data.today.average_sale_delta_pct'));
    }

    public function test_delta_is_null_never_a_fabricated_percentage_when_there_is_no_yesterday_baseline(): void
    {
        $owner = $this->user(['role_id' => 1], ['Sales_view']);
        $this->sale(['date' => '2026-05-15', 'GrandTotal' => 150]); // today only, no sales yesterday

        $response = $this->actingAs($owner, 'api')->getJson('/api/mobile/dashboard-test/summary')->assertStatus(200);
        $this->assertNull($response->json('data.today.sales_total_delta_pct'));
        $this->assertNull($response->json('data.today.average_sale_delta_pct'));
    }

    public function test_week_daily_totals_span_monday_to_today_with_real_zeros_for_days_without_sales(): void
    {
        $owner = $this->user(['role_id' => 1], ['Sales_view']);
        $this->sale(['date' => '2026-05-12', 'GrandTotal' => 300]); // Tuesday this week
        $this->sale(['date' => '2026-05-15', 'GrandTotal' => 100]); // Friday (today)

        $response = $this->actingAs($owner, 'api')->getJson('/api/mobile/dashboard-test/summary')->assertStatus(200);
        $week = $response->json('data.week');
        $this->assertSame('2026-05-11', $week['from']); // Monday
        $this->assertSame('2026-05-15', $week['to']);
        $days = collect($week['days'])->keyBy('date');
        $this->assertSame('0.00', $days['2026-05-11']['total']);
        $this->assertSame('300.00', $days['2026-05-12']['total']);
        $this->assertSame('0.00', $days['2026-05-13']['total']);
        $this->assertSame('0.00', $days['2026-05-14']['total']);
        $this->assertSame('100.00', $days['2026-05-15']['total']);
        $this->assertSame('400.00', $week['total']);
        $this->assertCount(5, $week['days']); // Monday..Friday only, never future days
    }

    public function test_top_products_included_only_with_reports_sales_permission(): void
    {
        $withReports = $this->user(['role_id' => 2], ['Sales_view', 'Reports_sales']);
        $withoutReports = $this->user(['role_id' => 2], ['Sales_view']);
        $productId = DB::table('products')->insertGetId(['name' => 'Producto Top', 'created_at' => now(), 'updated_at' => now()]);
        $sale = $this->sale(['date' => '2026-05-15', 'GrandTotal' => 100, 'user_id' => $withReports->id]);
        $this->saleDetail($sale->id, ['product_id' => $productId, 'quantity' => 5]);
        $sale2 = $this->sale(['date' => '2026-05-15', 'GrandTotal' => 100, 'user_id' => $withoutReports->id]);
        $this->saleDetail($sale2->id, ['product_id' => $productId, 'quantity' => 5]);

        $withResponse = $this->actingAs($withReports, 'api')->getJson('/api/mobile/dashboard-test/summary')->assertStatus(200);
        $this->assertNotNull($withResponse->json('data.today.top_products'));

        $withoutResponse = $this->actingAs($withoutReports, 'api')->getJson('/api/mobile/dashboard-test/summary')->assertStatus(200);
        $this->assertNull($withoutResponse->json('data.today.top_products'));
    }

    public function test_non_owner_only_sees_totals_within_their_branch(): void
    {
        $branchA = $this->branch();
        $branchB = $this->branch();
        $user = $this->user(['role_id' => 2], ['Sales_view'], [$branchA->id]);
        $this->sale(['date' => '2026-05-15', 'branch_id' => $branchA->id, 'GrandTotal' => 100]);
        $this->sale(['date' => '2026-05-15', 'branch_id' => $branchB->id, 'GrandTotal' => 900]);

        $response = $this->actingAs($user, 'api')->getJson('/api/mobile/dashboard-test/summary')->assertStatus(200);
        $this->assertSame('100.00', $response->json('data.today.sales_total'));
    }

    public function test_endpoint_never_mutates_any_sale(): void
    {
        $owner = $this->user(['role_id' => 1], ['Sales_view']);
        $sale = $this->sale(['date' => '2026-05-15']);
        $before = (array) DB::table('sales')->where('id', $sale->id)->first();

        $this->actingAs($owner, 'api')->getJson('/api/mobile/dashboard-test/summary')->assertStatus(200);
        $after = (array) DB::table('sales')->where('id', $sale->id)->first();

        $this->assertSame($before, $after);
    }
}
