<?php

namespace Tests\Feature;

use App\Http\Controllers\Mobile\MobileInventoryController;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MobileInventoryEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createInventorySchema();

        Route::middleware('auth:api')->get(
            '/api/mobile/inventory-test',
            MobileInventoryController::class
        );

        Gate::before(fn ($user = null) => true);
    }

    // ------------------------------------------------------------------
    // AUTH / TENANCY
    // ------------------------------------------------------------------

    public function test_requires_authentication_and_inventory_location(): void
    {
        $this->getJson('/api/mobile/inventory-test?inventory_location_id=1')
            ->assertStatus(401);

        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_location');
    }

    public function test_real_tenant_route_is_inside_pos_feature_group(): void
    {
        $routes = file_get_contents(base_path('routes/tenant_api.php'));

        $this->assertMatchesRegularExpression(
            "/Route::middleware\\('tenant\\.feature:pos'\\)->group\\(function \\(\\) \\{.*mobile\\/pos\\/catalog.*mobile\\/inventory/s",
            $routes
        );
    }

    public function test_rejects_invalid_or_forbidden_location(): void
    {
        $allowed = $this->branchLocation(['code' => 'ALLOWED']);
        $forbidden = $this->branchLocation(['code' => 'FORBID']);
        $inactive = $this->branchLocation(['code' => 'OFF', 'is_active' => false]);
        $user = $this->user(['role_id' => 2, 'default_inventory_location_id' => $allowed->id]);

        $this->actingAs($user, 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$forbidden->id)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden_location');

        $this->actingAs($this->user(['role_id' => 1]), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$inactive->id)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_location');
    }

    // ------------------------------------------------------------------
    // READINESS
    // ------------------------------------------------------------------

    public function test_branch_owned_location_is_ready_without_any_transition_state(): void
    {
        $location = $this->branchLocation();
        $product = $this->product(['name' => 'Café', 'code' => 'READY-1']);
        $this->stock($location->id, $product->id, null, 10, 0);

        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.items');
    }

    public function test_warehouse_owned_location_not_ready_without_transition_state(): void
    {
        $location = $this->warehouseLocation(['warehouse_id' => 501]);

        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'inventory_not_ready');
    }

    public function test_warehouse_owned_location_not_ready_when_transition_state_not_healthy_location_primary(): void
    {
        $location = $this->warehouseLocation(['warehouse_id' => 502]);
        $this->transitionState(502, $location->id, 'dual_write', 'pending');

        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'inventory_not_ready');
    }

    public function test_warehouse_owned_location_ready_when_transition_state_healthy_location_primary(): void
    {
        $location = $this->warehouseLocation(['warehouse_id' => 503]);
        $this->transitionState(503, $location->id, 'location_primary', 'healthy');
        $product = $this->product(['name' => 'Aceite', 'code' => 'READY-2']);
        $this->stock($location->id, $product->id, null, 4, 0);

        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.items');
    }

    // ------------------------------------------------------------------
    // CATALOG / ELIGIBILITY
    // ------------------------------------------------------------------

    public function test_returns_simple_product_with_category_and_stock(): void
    {
        $location = $this->branchLocation();
        $category = $this->category('Bebidas');
        $product = $this->product([
            'name' => 'Café molido',
            'code' => '000123',
            'gtin' => '7501234567890',
            'category_id' => $category,
            'stock_alert' => 5,
        ]);
        $this->stock($location->id, $product->id, null, 10, 2);

        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id)
            ->assertOk()
            ->assertJsonPath('data.items.0.product_id', $product->id)
            ->assertJsonPath('data.items.0.product_variant_id', null)
            ->assertJsonPath('data.items.0.display_name', 'Café molido')
            ->assertJsonPath('data.items.0.code', '000123')
            ->assertJsonPath('data.items.0.gtin', '7501234567890')
            ->assertJsonPath('data.items.0.category.name', 'Bebidas')
            ->assertJsonPath('data.items.0.inventory.quantity', '10.000')
            ->assertJsonPath('data.items.0.inventory.reserved_quantity', '2.000')
            ->assertJsonPath('data.items.0.inventory.available_quantity', '8.000')
            ->assertJsonPath('data.items.0.inventory.low_stock', false)
            ->assertJsonPath('data.items.0.inventory.out_of_stock', false)
            ->assertJsonMissingPath('data.items.0.pricing')
            ->assertJsonMissingPath('data.items.0.sellability')
            ->assertJsonPath('data.categories.0.name', 'Bebidas');
    }

    public function test_returns_one_row_per_variant_and_never_the_variant_parent(): void
    {
        $location = $this->branchLocation();
        $parent = $this->product(['name' => 'Camisa', 'code' => 'CAM-PARENT', 'is_variant' => 1]);
        $small = $this->variant($parent, ['name' => 'S', 'code' => 'CAM-S']);
        $large = $this->variant($parent, ['name' => 'L', 'code' => 'CAM-L']);
        $this->stock($location->id, $parent->id, $small->id, 3, 1);
        $this->stock($location->id, $parent->id, $large->id, 7, 0);

        $response = $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id);

        $response->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.pagination.total', 2);

        $items = collect($response->json('data.items'))->keyBy('code');
        $this->assertSame($small->id, $items['CAM-S']['product_variant_id']);
        $this->assertSame('Camisa · S', $items['CAM-S']['display_name']);
        $this->assertSame('2.000', $items['CAM-S']['inventory']['available_quantity']);
    }

    public function test_includes_not_selling_product_unlike_pos_catalog(): void
    {
        $location = $this->branchLocation();
        $product = $this->product(['name' => 'Pausado en POS', 'code' => 'NOSELL', 'not_selling' => 1]);
        $this->stock($location->id, $product->id, null, 5, 0);

        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.code', 'NOSELL');
    }

    public function test_excludes_service_and_combo_products(): void
    {
        $location = $this->branchLocation();
        $this->product(['type' => 'is_service', 'name' => 'Instalación', 'code' => 'SERV']);
        $this->product(['type' => 'is_combo', 'name' => 'Combo familiar', 'code' => 'COMBO']);
        $physical = $this->product(['name' => 'Físico', 'code' => 'PHYS']);
        $this->stock($location->id, $physical->id, null, 1, 0);

        $response = $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id);

        $response->assertOk()->assertJsonCount(1, 'data.items');
        $this->assertSame('PHYS', $response->json('data.items.0.code'));
    }

    public function test_excludes_inactive_and_deleted_products(): void
    {
        $location = $this->branchLocation();
        $visible = $this->product(['name' => 'Visible', 'code' => 'VISIBLE']);
        $this->product(['name' => 'Inactive', 'code' => 'INACTIVE', 'is_active' => 0]);
        $deleted = $this->product(['name' => 'Deleted', 'code' => 'DELETED']);
        $deleted->forceFill(['deleted_at' => now()])->save();

        $response = $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id);

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.product_id', $visible->id);
    }

    // ------------------------------------------------------------------
    // STOCK SEMANTICS
    // ------------------------------------------------------------------

    public function test_weighted_decimal_quantity_precision_is_preserved(): void
    {
        $location = $this->branchLocation();
        $product = $this->product(['name' => 'Queso', 'code' => 'WEIGHTED']);
        $this->stock($location->id, $product->id, null, 12.345, 0.345);

        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id)
            ->assertOk()
            ->assertJsonPath('data.items.0.inventory.quantity', '12.345')
            ->assertJsonPath('data.items.0.inventory.reserved_quantity', '0.345')
            ->assertJsonPath('data.items.0.inventory.available_quantity', '12.000');
    }

    public function test_zero_stock_marks_out_of_stock(): void
    {
        $location = $this->branchLocation();
        $product = $this->product(['name' => 'Agotado', 'code' => 'EMPTY']);
        $this->stock($location->id, $product->id, null, 0, 0);

        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id)
            ->assertOk()
            ->assertJsonPath('data.items.0.inventory.out_of_stock', true)
            ->assertJsonPath('data.items.0.inventory.low_stock', false);
    }

    public function test_low_stock_uses_product_stock_alert(): void
    {
        $location = $this->branchLocation();
        $product = $this->product(['name' => 'Bajo', 'code' => 'LOW', 'stock_alert' => 5]);
        $this->stock($location->id, $product->id, null, 4, 0);

        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id)
            ->assertOk()
            ->assertJsonPath('data.items.0.inventory.low_stock', true)
            ->assertJsonPath('data.items.0.inventory.out_of_stock', false);
    }

    public function test_stock_alert_null_or_zero_does_not_create_fake_low_stock(): void
    {
        $location = $this->branchLocation();
        $noAlert = $this->product(['name' => 'Sin umbral', 'code' => 'NOALERT', 'stock_alert' => 0]);
        $this->stock($location->id, $noAlert->id, null, 1, 0);

        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id)
            ->assertOk()
            ->assertJsonPath('data.items.0.inventory.low_stock', false);
    }

    /**
     * allow_overselling is a POS "can I still sell past zero" flag. It must
     * never leak into Inventory's out_of_stock, which answers a physically
     * different question ("do units physically exist"). Traced in
     * MobilePosProductReadService::item(): $outOfStock = $manageStock &&
     * $available <= 0 — allow_overselling only feeds sellability.can_sell,
     * never inventory.out_of_stock, so this was already true before this
     * hardening pass; this test makes that guarantee explicit and permanent.
     */
    public function test_out_of_stock_is_physical_and_ignores_pos_overselling(): void
    {
        $location = $this->branchLocation();
        $product = $this->product(['name' => 'Agotado con overselling', 'code' => 'OVERSELL-EMPTY']);
        $this->stock($location->id, $product->id, null, 0, 0);
        $this->setOverselling(true);

        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id)
            ->assertOk()
            ->assertJsonPath('data.items.0.inventory.manage_stock', true)
            ->assertJsonPath('data.items.0.inventory.available_quantity', '0.000')
            ->assertJsonPath('data.items.0.inventory.out_of_stock', true);
    }

    /**
     * item() computes low_stock and out_of_stock independently, so both CAN be
     * true at once (available=0, stock_alert>0). Inventory's contract treats
     * these as mutually exclusive — out_of_stock wins — resolved once in
     * MobileInventoryService::computeRows() so the item, the stock_status
     * filter and the summary can never disagree.
     */
    public function test_out_of_stock_and_low_stock_are_mutually_exclusive(): void
    {
        $location = $this->branchLocation();
        $product = $this->product(['name' => 'Cero con umbral', 'code' => 'ZERO-WITH-ALERT', 'stock_alert' => 5]);
        $this->stock($location->id, $product->id, null, 0, 0);

        $item = $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id)
            ->assertOk()
            ->json('data.items.0');

        $this->assertTrue($item['inventory']['out_of_stock']);
        $this->assertFalse($item['inventory']['low_stock']);

        // stock_status=low_stock must not pick it up despite stock_alert > available.
        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id.'&stock_status=low_stock')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');

        // stock_status=out_of_stock must pick it up.
        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id.'&stock_status=out_of_stock')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');

        // summary must classify it as out_of_stock only, never low_stock.
        $summary = $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id)
            ->json('data.summary');
        $this->assertSame(1, $summary['out_of_stock_count']);
        $this->assertSame(0, $summary['low_stock_count']);
    }

    // ------------------------------------------------------------------
    // SEARCH
    // ------------------------------------------------------------------

    public function test_search_matches_name_code_gtin_variant_code_and_variant_gtin(): void
    {
        $location = $this->branchLocation();
        $this->product(['name' => 'Agua', 'code' => '000001', 'gtin' => 'GTIN-A']);
        $parent = $this->product(['name' => 'Zapato', 'code' => 'ZAP', 'is_variant' => 1]);
        $this->variant($parent, ['name' => 'Azul', 'code' => 'VAR-COD', 'gtin' => 'VAR-GTIN']);

        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id.'&search=Agua')
            ->assertOk()->assertJsonPath('data.pagination.total', 1);

        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id.'&search=000001')
            ->assertOk()->assertJsonPath('data.pagination.total', 1);

        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id.'&search=GTIN-A')
            ->assertOk()->assertJsonPath('data.pagination.total', 1);

        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id.'&search=VAR-COD')
            ->assertOk()->assertJsonPath('data.pagination.total', 1);

        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id.'&search=VAR-GTIN')
            ->assertOk()->assertJsonPath('data.pagination.total', 1);
    }

    // ------------------------------------------------------------------
    // FILTERS
    // ------------------------------------------------------------------

    public function test_category_filter(): void
    {
        $location = $this->branchLocation();
        $bebidas = $this->category('Bebidas');
        $otros = $this->category('Otros');
        $this->product(['name' => 'Agua', 'code' => 'AGUA', 'category_id' => $bebidas]);
        $this->product(['name' => 'Galleta', 'code' => 'GALLETA', 'category_id' => $otros]);

        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id.'&category_id='.$otros)
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.name', 'Galleta');
    }

    public function test_stock_status_filter_low_stock_and_out_of_stock(): void
    {
        $location = $this->branchLocation();
        $low = $this->product(['name' => 'Bajo', 'code' => 'LOW1', 'stock_alert' => 5]);
        $this->stock($location->id, $low->id, null, 3, 0);
        $out = $this->product(['name' => 'Agotado', 'code' => 'OUT1']);
        $this->stock($location->id, $out->id, null, 0, 0);
        $healthy = $this->product(['name' => 'Sano', 'code' => 'OK1']);
        $this->stock($location->id, $healthy->id, null, 20, 0);

        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id.'&stock_status=low_stock')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.code', 'LOW1');

        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id.'&stock_status=out_of_stock')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.code', 'OUT1');
    }

    /**
     * CRITICAL: stock_status must be applied BEFORE pagination — filtering the
     * current page in PHP after slicing would corrupt totals/last_page/has_more.
     */
    public function test_stock_status_filter_produces_correct_pagination_totals_across_pages(): void
    {
        $location = $this->branchLocation();

        for ($i = 1; $i <= 10; $i++) {
            $low = $this->product(['name' => sprintf('Bajo %02d', $i), 'code' => sprintf('LOW-%02d', $i), 'stock_alert' => 5]);
            $this->stock($location->id, $low->id, null, 3, 0);
        }
        for ($i = 1; $i <= 6; $i++) {
            $healthy = $this->product(['name' => sprintf('Sano %02d', $i), 'code' => sprintf('OK-%02d', $i)]);
            $this->stock($location->id, $healthy->id, null, 50, 0);
        }

        $first = $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id.'&stock_status=low_stock&per_page=4&page=1');
        $second = $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id.'&stock_status=low_stock&per_page=4&page=2');
        $third = $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id.'&stock_status=low_stock&per_page=4&page=3');

        $first->assertOk()
            ->assertJsonCount(4, 'data.items')
            ->assertJsonPath('data.pagination.total', 10)
            ->assertJsonPath('data.pagination.last_page', 3)
            ->assertJsonPath('data.pagination.has_more', true);
        $second->assertOk()->assertJsonCount(4, 'data.items')->assertJsonPath('data.pagination.has_more', true);
        $third->assertOk()->assertJsonCount(2, 'data.items')->assertJsonPath('data.pagination.has_more', false);

        $codes = collect($first->json('data.items'))->pluck('code')
            ->merge(collect($second->json('data.items'))->pluck('code'))
            ->merge(collect($third->json('data.items'))->pluck('code'));
        $this->assertCount(10, $codes->unique());
        $this->assertTrue($codes->every(fn ($code) => str_starts_with($code, 'LOW-')));
    }

    // ------------------------------------------------------------------
    // SUMMARY
    // ------------------------------------------------------------------

    public function test_summary_counts_are_correct(): void
    {
        $location = $this->branchLocation();
        $low = $this->product(['name' => 'Bajo', 'code' => 'SUM-LOW', 'stock_alert' => 5]);
        $this->stock($location->id, $low->id, null, 3, 0);
        $out = $this->product(['name' => 'Agotado', 'code' => 'SUM-OUT']);
        $this->stock($location->id, $out->id, null, 0, 0);
        $healthy = $this->product(['name' => 'Sano', 'code' => 'SUM-OK']);
        $this->stock($location->id, $healthy->id, null, 20, 0);

        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id)
            ->assertOk()
            ->assertJsonPath('data.summary.total_items', 3)
            ->assertJsonPath('data.summary.low_stock_count', 1)
            ->assertJsonPath('data.summary.out_of_stock_count', 1);
    }

    public function test_summary_is_location_wide_and_ignores_active_filters(): void
    {
        $location = $this->branchLocation();
        $bebidas = $this->category('Bebidas');
        $agua = $this->product(['name' => 'Agua', 'code' => 'SCOPE-A', 'category_id' => $bebidas]);
        $this->stock($location->id, $agua->id, null, 20, 0);
        $out = $this->product(['name' => 'Agotado', 'code' => 'SCOPE-OUT']);
        $this->stock($location->id, $out->id, null, 0, 0);

        $response = $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id.'&search=Agua');

        $response->assertOk()
            ->assertJsonPath('data.pagination.total', 1) // filtered list narrowed by search
            ->assertJsonPath('data.summary.total_items', 2) // summary stays location-wide
            ->assertJsonPath('data.summary.out_of_stock_count', 1);
    }

    // ------------------------------------------------------------------
    // PAGINATION
    // ------------------------------------------------------------------

    public function test_paginates_with_max_per_page_and_does_not_duplicate_items(): void
    {
        $location = $this->branchLocation();
        for ($i = 1; $i <= 55; $i++) {
            $this->product(['name' => sprintf('Producto %02d', $i), 'code' => sprintf('P%02d', $i)]);
        }

        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id.'&per_page=51')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');

        $first = $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id.'&per_page=50&page=1');
        $second = $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id.'&per_page=50&page=2');

        $first->assertOk()
            ->assertJsonCount(50, 'data.items')
            ->assertJsonPath('data.pagination.total', 55)
            ->assertJsonPath('data.pagination.last_page', 2)
            ->assertJsonPath('data.pagination.has_more', true);

        $second->assertOk()
            ->assertJsonCount(5, 'data.items')
            ->assertJsonPath('data.pagination.has_more', false);

        $codes = collect($first->json('data.items'))->pluck('code')
            ->merge(collect($second->json('data.items'))->pluck('code'));
        $this->assertCount(55, $codes->unique());
    }

    /**
     * Query count must stay flat (bulk loading) as N grows, not +1 per row
     * (N+1). Deliberately mixes rows WITH and WITHOUT an inventory_location_stocks
     * row — the missing-row case is exactly what previously triggered an extra
     * query per row inside MobilePosProductReadService::item()'s internal
     * fallback lookup before the zero-stock stub fix. Compares growth, not an
     * exact number, so it doesn't become a frozen/fragile assertion.
     */
    public function test_query_count_stays_flat_as_item_count_grows_bulk_loading_not_n_plus_one(): void
    {
        $location = $this->branchLocation();

        for ($i = 1; $i <= 3; $i++) {
            $product = $this->product(['name' => sprintf('Base %02d', $i), 'code' => sprintf('BASE-%02d', $i)]);
            if ($i % 2 === 0) {
                $this->stock($location->id, $product->id, null, 5, 0);
            } // odd ones deliberately left with NO stock row
        }

        \DB::enableQueryLog();
        \DB::flushQueryLog();
        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id.'&per_page=50')
            ->assertOk()
            ->assertJsonCount(3, 'data.items');
        $smallQueryCount = count(\DB::getQueryLog());

        for ($i = 1; $i <= 60; $i++) {
            $product = $this->product(['name' => sprintf('Extra %02d', $i), 'code' => sprintf('EXTRA-%02d', $i)]);
            if ($i % 2 === 0) {
                $this->stock($location->id, $product->id, null, 5, 0);
            } // half still left with NO stock row
        }

        \DB::flushQueryLog();
        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/inventory-test?inventory_location_id='.$location->id.'&per_page=50')
            ->assertOk()
            ->assertJsonCount(50, 'data.items') // page 1 of 63 total rows
            ->assertJsonPath('data.pagination.total', 63);
        $largeQueryCount = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        // 63 vs 3 rows (21x) must NOT translate into anywhere near 21x queries.
        // A generous constant slack (not a per-row multiplier) proves bulk loading.
        $this->assertLessThanOrEqual(
            $smallQueryCount + 5,
            $largeQueryCount,
            "Query count grew from {$smallQueryCount} to {$largeQueryCount} across a 21x item increase — looks like N+1."
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function user(array $overrides = []): User
    {
        $user = new User();
        $user->forceFill(array_merge([
            'firstname' => 'Mobile',
            'lastname' => 'Cashier',
            'email' => uniqid('mobile-', false).'@example.test',
            'password' => bcrypt('secret'),
            'statut' => 1,
            'role_id' => 1,
            'default_inventory_location_id' => null,
        ], $overrides))->save();

        return $user;
    }

    private function branchLocation(array $overrides = []): InventoryLocation
    {
        return InventoryLocation::create(array_merge([
            'branch_id' => 1,
            'warehouse_id' => null,
            'code' => uniqid('PISO-', false),
            'name' => 'Piso de venta',
            'type' => InventoryLocation::TYPE_SALES_FLOOR,
            'is_sellable' => true,
            'is_default_sales' => true,
            'is_quarantine' => false,
            'is_active' => true,
        ], $overrides));
    }

    private function warehouseLocation(array $overrides = []): InventoryLocation
    {
        return InventoryLocation::create(array_merge([
            'branch_id' => null,
            'warehouse_id' => 999,
            'code' => uniqid('CD-', false),
            'name' => 'Inventario principal',
            'type' => InventoryLocation::TYPE_STORAGE,
            'is_sellable' => true,
            'is_default_sales' => false,
            'is_quarantine' => false,
            'is_active' => true,
        ], $overrides));
    }

    private function transitionState(int $warehouseId, int $locationId, string $mode, string $status): void
    {
        \DB::table('inventory_transition_states')->insert([
            'warehouse_id' => $warehouseId,
            'inventory_location_id' => $locationId,
            'mode' => $mode,
            'status' => $status,
            'mismatch_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function category(string $name): int
    {
        return (int) \DB::table('categories')->insertGetId([
            'name' => $name,
            'code' => uniqid('CAT-', false),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function product(array $overrides = []): Product
    {
        $product = new Product();
        $product->forceFill(array_merge([
            'type' => 'is_single',
            'name' => 'Café',
            'code' => uniqid('SKU-', false),
            'gtin' => null,
            'Type_barcode' => 'CODE128',
            'cost' => 10,
            'price' => 20,
            'wholesale_price' => 0,
            'min_price' => 0,
            'category_id' => null,
            'unit_sale_id' => 1,
            'TaxNet' => 0,
            'tax_method' => '1',
            'discount' => 0,
            'discount_method' => '2',
            'stock_alert' => 0,
            'is_variant' => 0,
            'is_active' => 1,
            'not_selling' => 0,
            'image' => 'no-image.png',
        ], $overrides))->save();

        return $product;
    }

    private function variant(Product $product, array $overrides = []): ProductVariant
    {
        return ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'name' => 'Variant',
            'cost' => 10,
            'price' => 30,
            'wholesale' => 0,
            'min_price' => 0,
            'code' => uniqid('VAR-', false),
            'gtin' => null,
            'image' => 'no-image.png',
        ], $overrides));
    }

    private function stock(int $locationId, int $productId, ?int $variantId, float $quantity, float $reserved): void
    {
        \DB::table('inventory_location_stocks')->insert([
            'inventory_location_id' => $locationId,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'variant_key' => $variantId ?: 0,
            'quantity' => $quantity,
            'reserved_quantity' => $reserved,
            'manage_stock' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function setOverselling(bool $enabled): void
    {
        \DB::table('pos_settings')->delete();
        \DB::table('pos_settings')->insert([
            'allow_overselling' => $enabled,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createInventorySchema(): void
    {
        Schema::create('users', function ($table) {
            $table->integer('id', true);
            $table->string('firstname')->nullable();
            $table->string('lastname')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->integer('statut')->default(1);
            $table->integer('role_id')->default(1);
            $table->integer('default_inventory_location_id')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('units', function ($table) {
            $table->integer('id', true);
            $table->string('ShortName')->nullable();
            $table->timestamps();
        });
        \DB::table('units')->insert(['id' => 1, 'ShortName' => 'u', 'created_at' => now(), 'updated_at' => now()]);

        Schema::create('categories', function ($table) {
            $table->integer('id', true);
            $table->string('name');
            $table->string('code')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('inventory_locations', function ($table) {
            $table->integer('id', true);
            $table->integer('branch_id')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->string('type');
            $table->boolean('is_sellable')->default(false);
            $table->boolean('is_default_sales')->default(false);
            $table->boolean('is_quarantine')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('inventory_transition_states', function ($table) {
            $table->integer('id', true);
            $table->integer('warehouse_id')->unique();
            $table->integer('inventory_location_id')->nullable();
            $table->string('mode', 40)->default('legacy_only');
            $table->string('status', 40)->default('pending');
            $table->unsignedInteger('mismatch_count')->default(0);
            $table->timestamp('last_audited_at')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamp('shadow_enabled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('products', function ($table) {
            $table->integer('id', true);
            $table->string('type')->default('is_single');
            $table->string('name');
            $table->string('code', 192);
            $table->string('gtin', 64)->nullable();
            $table->string('Type_barcode', 192);
            $table->decimal('cost', 15)->default(0);
            $table->decimal('price', 15)->default(0);
            $table->decimal('wholesale_price', 15)->default(0);
            $table->decimal('min_price', 15)->default(0);
            $table->integer('category_id')->nullable();
            $table->integer('unit_sale_id')->nullable();
            $table->decimal('TaxNet', 15)->nullable()->default(0);
            $table->string('tax_method', 192)->nullable()->default('1');
            $table->decimal('discount', 15)->nullable()->default(0);
            $table->string('discount_method', 192)->nullable()->default('2');
            $table->decimal('stock_alert', 15)->nullable()->default(0);
            $table->boolean('is_variant')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('not_selling')->default(false);
            $table->string('image')->default('no-image.png');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_variants', function ($table) {
            $table->integer('id', true);
            $table->integer('product_id')->nullable();
            $table->string('name', 192)->nullable();
            $table->decimal('cost', 15)->default(0);
            $table->decimal('price', 15)->default(0);
            $table->decimal('wholesale', 15)->nullable()->default(0);
            $table->decimal('min_price', 15)->nullable()->default(0);
            $table->string('code', 192);
            $table->string('gtin', 64)->nullable();
            $table->string('image')->default('no-image.png');
            $table->decimal('qty')->nullable()->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('inventory_location_stocks', function ($table) {
            $table->integer('id', true);
            $table->integer('inventory_location_id');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->integer('variant_key')->default(0);
            $table->decimal('quantity', 12, 3)->default(0);
            $table->decimal('reserved_quantity', 12, 3)->default(0);
            $table->boolean('manage_stock')->default(true);
            $table->timestamps();
        });

        Schema::create('pos_settings', function ($table) {
            $table->integer('id', true);
            $table->boolean('allow_overselling')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
        $this->setOverselling(false);
    }
}
