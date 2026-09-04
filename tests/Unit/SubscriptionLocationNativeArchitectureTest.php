<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ============================================================================
 *  SUBSCRIPTION BILLING LOCATION-NATIVE — architecture contract (MS7-B2-4)
 * ============================================================================
 *
 * Pattern/marker based — NEVER line numbers.
 * Pins:
 *   - Subscription::generateInvoice() is the SINGLE safe entry point (both
 *     GenerateSubscriptionInvoices and SubscriptionController::store() call
 *     it) — no second/parallel stock engine.
 *   - it reuses LocationAwareSaleStockService (MS7-B1) +
 *     ExternalChannelInventoryService (MS7-B2-1) for a location_primary
 *     warehouse.
 *   - no native physical writer uses product_warehouse.
 *   - the legacy branch is now guarded against negative stock.
 *   - the whole document (Sale, SaleDetail, stock effect, payment, billing
 *     state) is one transaction — billing state advances only after
 *     everything else succeeds.
 *   - no batch/serial auto-selection.
 *   - the command isolates failures per subscription (no global rollback).
 *   - B2-1 Store, B2-3 Shopify, MS7-B1 Admin Sale/SaleReturn, WooCommerce,
 *     Dashboard/Report, promotion all stay untouched by this milestone.
 */
class SubscriptionLocationNativeArchitectureTest extends TestCase
{
    private function read(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$rel);
    }

    public function test_generate_invoice_is_the_single_entry_point_for_both_callers(): void
    {
        $command = $this->read('app/Console/Commands/GenerateSubscriptionInvoices.php');
        $this->assertStringContainsString('$subscription->generateInvoice()', $command);
        $this->assertStringNotContainsString('product_warehouse', $command);

        $controller = $this->read('app/Http/Controllers/SubscriptionController.php');
        $this->assertStringContainsString('$subscription->generateInvoice()', $controller);
    }

    public function test_subscription_model_routes_to_native_engine(): void
    {
        $src = $this->read('app/Models/Subscription.php');

        $this->assertStringContainsString('WarehouseInventoryModeResolver::class)->isLocationPrimary', $src);
        $this->assertStringContainsString('ExternalChannelInventoryService::class', $src);
        $this->assertStringContainsString('LocationAwareSaleStockService::class', $src);
    }

    public function test_subscription_model_never_writes_product_warehouse_when_native(): void
    {
        $src = $this->read('app/Models/Subscription.php');
        $this->assertMatchesRegularExpression('/if \(\$isNative\) \{.*?applySnapshot.*?\} else \{.*?product_warehouse/s', $src);
    }

    public function test_subscription_model_fails_closed_on_batch_or_serial(): void
    {
        $src = $this->read('app/Models/Subscription.php');
        $this->assertStringContainsString('requires_batch', $src);
        $this->assertStringContainsString('requires_serial', $src);
        $this->assertStringContainsString('cannot auto-bill', $src);
    }

    public function test_legacy_branch_is_guarded_against_negative_stock(): void
    {
        $src = $this->read('app/Models/Subscription.php');
        $this->assertMatchesRegularExpression('/lockForUpdate\(\).*?insufficient stock/s', $src);
    }

    public function test_whole_document_is_one_transaction_billing_state_advances_last(): void
    {
        $src = $this->read('app/Models/Subscription.php');
        $this->assertStringContainsString('DB::transaction(function ()', $src);
        // Billing state (remaining_cycles/next_billing_date) textually comes
        // AFTER the Sale/stock/payment work, inside the same closure.
        $saleCreatePos = strpos($src, 'Sale::create(');
        $remainingCyclesPos = strpos($src, 'remaining_cycles -= 1');
        $this->assertNotFalse($saleCreatePos);
        $this->assertNotFalse($remainingCyclesPos);
        $this->assertLessThan($remainingCyclesPos, $saleCreatePos);
    }

    public function test_command_isolates_failures_per_subscription(): void
    {
        $src = $this->read('app/Console/Commands/GenerateSubscriptionInvoices.php');
        $this->assertMatchesRegularExpression('/foreach \(\$subscriptionIds as \$subscriptionId\) \{\s*try \{/s', $src);
        $this->assertStringContainsString('continue', $src);
    }

    public function test_out_of_scope_surfaces_are_untouched(): void
    {
        foreach ([
            'app/Http/Controllers/SalesController.php',
            'app/Http/Controllers/SalesReturnController.php',
            'app/Http/Controllers/PosController.php',
            'app/Http/Controllers/Api/Store/OnlineOrdersApiController.php',
            'app/Http/Controllers/Api/Store/CheckoutController.php',
            'app/Http/Controllers/StoreFrontController.php',
            'app/Services/Shopify/SyncService.php',
            'app/Services/WooCommerce/SyncService.php',
            'app/Http/Controllers/DashboardController.php',
            'app/Http/Controllers/ReportController.php',
        ] as $rel) {
            $path = dirname(__DIR__, 2).'/'.$rel;
            if (! file_exists($path)) {
                continue;
            }
            $src = file_get_contents($path);
            $this->assertStringNotContainsString('MS7-B2-4', $src, "{$rel} must stay untouched by MS7-B2-4.");
        }
    }
}
