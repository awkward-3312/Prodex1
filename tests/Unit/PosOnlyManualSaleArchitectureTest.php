<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * PRODEX business rule: every NEW manual sale must originate exclusively from
 * the POS (PosController@CreatePOS). The administrative "Nueva venta" form and
 * the old Quotation -> Sale direct conversion are retired; a POS sale
 * (is_pos = 1) cannot be edited as a full transaction.
 *
 * These are source-level architecture assertions (no DB), matching the
 * existing *ArchitectureTest.php convention in this suite.
 */
class PosOnlyManualSaleArchitectureTest extends TestCase
{
    private function read(string $path): string
    {
        // Resolve from the repo root so the test runs with or without a
        // bootstrapped Laravel application (matches the rest of this suite's
        // intent while staying self-sufficient locally).
        $root = \dirname(__DIR__, 2);

        return file_get_contents($root.'/'.$path);
    }

    // ------------------------------------------------------------------
    // 1. Administrative sale creation is blocked server-side (403)
    // ------------------------------------------------------------------

    public function test_sales_controller_store_returns_403_for_manual_admin_sales(): void
    {
        $src = $this->read('app/Http/Controllers/SalesController.php');

        // The public store() entry point short-circuits with a 403 + message.
        $this->assertMatchesRegularExpression(
            '/public function store\(Request \$request\)\s*\{.*?MANUAL_SALE_POS_ONLY.*?Las ventas manuales deben registrarse desde el POS\..*?\], 403\);/s',
            $src,
            'SalesController@store must return HTTP 403 with the POS-only message.'
        );
    }

    public function test_legacy_admin_sale_body_is_preserved_but_unreachable(): void
    {
        $src = $this->read('app/Http/Controllers/SalesController.php');

        // Body kept for reference / location-native docs, moved to a private
        // method that nothing calls.
        $this->assertStringContainsString('private function storeLegacyAdminSale(Request $request)', $src);
        $this->assertStringContainsString('$order->is_pos = 0;', $src);
        $this->assertStringNotContainsString('return $this->storeLegacyAdminSale(', $src);
    }

    // ------------------------------------------------------------------
    // 2. POS, import and integrations are untouched
    // ------------------------------------------------------------------

    public function test_pos_create_pos_still_produces_an_is_pos_sale(): void
    {
        $src = $this->read('app/Http/Controllers/PosController.php');

        $this->assertStringContainsString('public function CreatePOS(Request $request', $src);
        $this->assertStringContainsString('$order->is_pos = 1;', $src);
        // The POS never routes through the blocked SalesController@store.
        $this->assertStringNotContainsString("SalesController@store", $src);
        $this->assertStringNotContainsString('MANUAL_SALE_POS_ONLY', $src);
    }

    public function test_store_import_sales_is_not_affected_by_the_block(): void
    {
        $routes = $this->read('routes/tenant_api.php');
        $src = $this->read('app/Http/Controllers/SalesController.php');

        $this->assertStringContainsString("Route::post('store_import_sales', 'SalesController@store_import_sales')", $routes);
        $this->assertStringContainsString('public function store_import_sales(', $src);

        // The import method must NOT carry the POS-only 403 guard.
        $importStart = strpos($src, 'public function store_import_sales(');
        $this->assertNotFalse($importStart);
        $importSlice = substr($src, $importStart, 4000);
        $this->assertStringNotContainsString('MANUAL_SALE_POS_ONLY', $importSlice);
    }

    public function test_sales_resource_route_is_still_registered(): void
    {
        $routes = $this->read('routes/tenant_api.php');
        $this->assertStringContainsString("Route::resource('sales', 'SalesController')", $routes);
    }

    // ------------------------------------------------------------------
    // 3. POS sales cannot be edited as a full transaction
    // ------------------------------------------------------------------

    public function test_update_is_blocked_for_pos_sales_only(): void
    {
        $src = $this->read('app/Http/Controllers/SalesController.php');

        $updateStart = strpos($src, 'public function update(Request $request, $id)');
        $this->assertNotFalse($updateStart);
        $updateSlice = substr($src, $updateStart, 3000);

        $this->assertStringContainsString("(int) \$__saleForEditGuard->is_pos === 1", $updateSlice);
        $this->assertStringContainsString('POS_SALE_NOT_EDITABLE', $updateSlice);
        $this->assertStringContainsString('], 403);', $updateSlice);
    }

    public function test_edit_form_loader_is_blocked_for_pos_sales_only(): void
    {
        $src = $this->read('app/Http/Controllers/SalesController.php');

        $editStart = strpos($src, 'public function edit(Request $request, $id)');
        $this->assertNotFalse($editStart);
        $editSlice = substr($src, $editStart, 2000);

        $this->assertStringContainsString("(int) \$__saleForEditGuard->is_pos === 1", $editSlice);
        $this->assertStringContainsString('POS_SALE_NOT_EDITABLE', $editSlice);
    }

    public function test_historical_admin_sales_keep_update_capability(): void
    {
        $src = $this->read('app/Http/Controllers/SalesController.php');

        // The guard is explicitly scoped to is_pos === 1; there is no blanket
        // block on update()/edit() and no is_pos === 0 rejection.
        $this->assertStringNotContainsString("is_pos === 0", $src);
        $this->assertStringContainsString('Historical administrative sales (is_pos = 0', $src);
    }

    public function test_sales_list_exposes_is_pos_so_the_ui_can_hide_edit(): void
    {
        // The live sales list is served by OperationalSalesController@index
        // (registered in routes/tenant_pos_reports.php, loaded after
        // tenant_api.php's Route::resource('sales', ...)).
        $reports = $this->read('routes/tenant_pos_reports.php');
        $this->assertStringContainsString("Route::get('sales', 'OperationalSalesController@index')", $reports);

        $op = $this->read('app/Http/Controllers/OperationalSalesController.php');
        $this->assertStringContainsString("'is_pos' => (int) \$sale->is_pos,", $op);

        // The historical controller also carries it, for the (overridden) resource index.
        $src = $this->read('app/Http/Controllers/SalesController.php');
        $this->assertStringContainsString("\$item['is_pos'] = (int) \$Sale['is_pos'];", $src);

        $list = $this->read('resources/src/views/app/pages/sales/index_sale.vue');
        $this->assertStringContainsString('Number(row.is_pos) !== 1', $list);
    }

    // ------------------------------------------------------------------
    // 4. Frontend: "Nueva venta" is gone, "Ir al POS" replaces it
    // ------------------------------------------------------------------

    public function test_navigation_no_longer_links_to_the_admin_sale_form(): void
    {
        foreach ([
            'resources/src/views/app/_ui/data/shell-nav.js',
            'resources/src/containers/layouts/largeSidebar/Sidebar.vue',
            'resources/src/containers/layouts/largeSidebar/VerticalSidebar.vue',
            'resources/src/views/app/pages/sales/index_sale.vue',
        ] as $file) {
            $this->assertStringNotContainsString('/app/sales/store', $this->read($file), "$file still links to the retired admin sale form.");
        }
    }

    public function test_sales_list_offers_go_to_pos_gated_by_pos_permission(): void
    {
        $list = $this->read('resources/src/views/app/pages/sales/index_sale.vue');

        $this->assertStringContainsString("includes('Pos_view')", $list);
        $this->assertStringContainsString('$router.push(\'/app/pos\')', $list);
        $this->assertStringContainsString("\$t('Go_to_POS')", $list);
        $this->assertStringNotContainsString("\$t('Add') }}</px-button>", $list);
    }

    public function test_shell_nav_replaces_new_sale_with_go_to_pos(): void
    {
        $nav = $this->read('resources/src/views/app/_ui/data/shell-nav.js');
        $this->assertStringNotContainsString('label: "Nueva venta"', $nav);
        $this->assertStringNotContainsString('route: "/app/sales/store"', $nav);
        $this->assertStringContainsString('label: "Ir al POS"', $nav);
    }

    public function test_router_redirects_the_old_admin_sale_url_away(): void
    {
        $router = $this->read('resources/src/router.js');

        $this->assertStringContainsString('beforeEnter: redirectManualSaleToPos', $router);
        $this->assertStringContainsString('function redirectManualSaleToPos', $router);
        $this->assertStringContainsString('next({ path: "/app/pos" })', $router);
        $this->assertStringContainsString('next({ name: "index_sales" })', $router);
    }

    // ------------------------------------------------------------------
    // 5. Quotation -> Sale direct conversion is retired; POS prefill instead
    // ------------------------------------------------------------------

    public function test_router_redirects_change_to_sale_into_the_pos_prefill(): void
    {
        $router = $this->read('resources/src/router.js');

        $this->assertStringContainsString('beforeEnter: redirectQuotationToPos', $router);
        $this->assertStringContainsString('function redirectQuotationToPos', $router);
        $this->assertStringContainsString('query: id ? { quotation_id: id } : {}', $router);
    }

    public function test_quotation_detail_processes_through_pos_not_change_to_sale(): void
    {
        $detail = $this->read('resources/src/views/app/pages/quotations/detail_quotation.vue');

        $this->assertStringNotContainsString("name: 'change_to_sale'", $detail);
        $this->assertStringContainsString('path: \'/app/pos\', query: { quotation_id: $route.params.id }', $detail);
        $this->assertStringContainsString("\$t('Process_in_POS')", $detail);
    }

    public function test_quotation_prefill_endpoint_exists_and_creates_nothing(): void
    {
        $routes = $this->read('routes/tenant_api.php');
        $this->assertStringContainsString("Route::get('pos/data_quotation_prefill/{id}', 'PosController@data_quotation_prefill')", $routes);

        $src = $this->read('app/Http/Controllers/PosController.php');
        $start = strpos($src, 'public function data_quotation_prefill(Request $request, $id)');
        $this->assertNotFalse($start);
        $end = strpos($src, 'public function GetProductsByParametre(', $start);
        $this->assertNotFalse($end);
        $method = substr($src, $start, $end - $start);

        // Read-only: no persistence, no stock movement, no conversion flag.
        $this->assertStringContainsString('$this->authorizeForUser($request->user(\'api\'), \'Sales_pos\', Sale::class);', $method);
        $this->assertStringNotContainsString('new Sale', $method);
        $this->assertStringNotContainsString('new DraftSale', $method);
        $this->assertStringNotContainsString('PaymentSale::', $method);
        $this->assertStringNotContainsString('->save()', $method);
        $this->assertStringNotContainsString('->update(', $method);
        $this->assertStringNotContainsString('PromotionUsage::', $method);
        $this->assertStringNotContainsString('InventoryService', $method);
        $this->assertStringNotContainsString('->decrease(', $method);
    }

    public function test_pos_component_prefill_does_not_mark_a_draft(): void
    {
        $pos = $this->read('resources/src/views/app/pages/pos.vue');

        $start = strpos($pos, 'loadQuotationPrefill(id) {');
        $this->assertNotFalse($start);
        $end = strpos($pos, 'loadDraftSale(id) {', $start);
        $this->assertNotFalse($end);
        $method = substr($pos, $start, $end - $start);

        // Prefill must never claim the cart is a held draft.
        $this->assertStringNotContainsString('this.draft_sale_id', $method);
        $this->assertStringContainsString('pos/data_quotation_prefill/', $method);
        $this->assertStringContainsString('this.details = mapped;', $method);

        // The POS confirms the sale through the unchanged CreatePOS path.
        $this->assertStringContainsString("axios.post('/pos/create_pos', payload)", $pos);
    }
}
