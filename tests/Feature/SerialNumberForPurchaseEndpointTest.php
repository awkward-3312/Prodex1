<?php

namespace Tests\Feature;

use App\Http\Controllers\SerialNumberController;
use App\Models\ProductSerial;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\Support\SerialTestSchema;
use Tests\TestCase;

/**
 * MS6-B2 — §33: `serial_numbers/for_purchase` audited + extended for the
 * location-native PurchaseReturn serial-select widget.
 *
 * `purchase_id` stays the LEGACY hard filter when present (unchanged
 * behaviour for every existing caller — A). `inventory_location_id` is an
 * OPTIONAL additive filter (B, C). A non-variant line never leaks another
 * variant's serials of the same product (D, audit fix). `purchase_id`
 * present -> linked-purchase provenance (E); omitted -> unlinked, product/
 * variant/location only, never assumed as purchase_id=0 (F).
 */
class SerialNumberForPurchaseEndpointTest extends TestCase
{
    use LegacyPurchaseTestSchema;
    use SerialTestSchema;

    private int $wh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLegacyPurchaseSchema();
        $this->buildSerialSchema();
        $this->legacyOwner();
        $this->wh = $this->makeWarehouse('EP-WH');
    }

    private function controller(): SerialNumberController
    {
        return new SerialNumberController;
    }

    private function seedRow(string $sn, int $productId, array $overrides = []): int
    {
        return (int) DB::table('product_serials')->insertGetId(array_merge([
            'serial_number' => $sn, 'product_id' => $productId, 'product_variant_id' => null,
            'warehouse_id' => $this->wh, 'inventory_location_id' => null,
            'status' => ProductSerial::STATUS_AVAILABLE, 'purchase_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function names(array $params): array
    {
        $res = $this->controller()->forPurchase($this->makeRequest($params, 'GET'));
        $data = json_decode($res->getContent(), true);

        return array_column($data['serials'], 'serial_number');
    }

    // ===================== A — legacy: no location param, unchanged =======

    public function test_A_legacy_request_without_location_keeps_current_behaviour(): void
    {
        $p = (int) $this->makeProduct(['code' => 'A1']);
        $this->seedRow('A-1', $p, ['purchase_id' => 100]);
        $this->seedRow('A-2', $p, ['purchase_id' => 200]); // different purchase

        $names = $this->names(['purchase_id' => 100, 'product_id' => $p]);

        $this->assertSame(['A-1'], $names);
    }

    // ===================== B/C — location filter, optional, additive ======

    public function test_B_location_filter_only_returns_serials_at_that_location(): void
    {
        $p = (int) $this->makeProduct(['code' => 'B1']);
        $this->seedRow('B-LOC1', $p, ['purchase_id' => 1, 'inventory_location_id' => 11]);
        $this->seedRow('B-LOC2', $p, ['purchase_id' => 1, 'inventory_location_id' => 22]);

        $names = $this->names(['purchase_id' => 1, 'product_id' => $p, 'inventory_location_id' => 11]);

        $this->assertSame(['B-LOC1'], $names);
    }

    public function test_C_no_location_param_does_not_leak_scope(): void
    {
        // C — omitting the location param must not silently narrow OR widen the
        // result beyond the legacy purchase_id + product_id contract.
        $p = (int) $this->makeProduct(['code' => 'C1']);
        $this->seedRow('C-LOC1', $p, ['purchase_id' => 5, 'inventory_location_id' => 11]);
        $this->seedRow('C-LOC2', $p, ['purchase_id' => 5, 'inventory_location_id' => 22]);

        $names = $this->names(['purchase_id' => 5, 'product_id' => $p]);

        sort($names);
        $this->assertSame(['C-LOC1', 'C-LOC2'], $names, 'both locations visible — legacy behaviour when location is omitted');
    }

    // ===================== D — variant filter, never mixed ===============

    public function test_D_non_variant_line_never_sees_variant_serials(): void
    {
        $p = (int) $this->makeProduct(['code' => 'D1', 'type' => 'is_variant']);
        $v = $this->makeVariant($p, 'V1');
        $this->seedRow('D-PLAIN', $p, ['purchase_id' => 1, 'product_variant_id' => null]);
        $this->seedRow('D-VARIANT', $p, ['purchase_id' => 1, 'product_variant_id' => $v]);

        $plain = $this->names(['purchase_id' => 1, 'product_id' => $p]);
        $this->assertSame(['D-PLAIN'], $plain);

        $variant = $this->names(['purchase_id' => 1, 'product_id' => $p, 'product_variant_id' => $v]);
        $this->assertSame(['D-VARIANT'], $variant);
    }

    // ===================== E — linked purchase filter =====================

    public function test_E_linked_purchase_filters_by_origin(): void
    {
        $p = (int) $this->makeProduct(['code' => 'E1']);
        $this->seedRow('E-MINE', $p, ['purchase_id' => 7]);
        $this->seedRow('E-OTHER', $p, ['purchase_id' => 8]);

        $names = $this->names(['purchase_id' => 7, 'product_id' => $p]);

        $this->assertSame(['E-MINE'], $names);
    }

    // ===================== F — unlinked (no purchase_id) ===================

    public function test_F_unlinked_return_resolves_by_product_variant_location_only(): void
    {
        $p = (int) $this->makeProduct(['code' => 'F1']);
        $this->seedRow('F-A', $p, ['purchase_id' => 1, 'inventory_location_id' => 11]);
        $this->seedRow('F-B', $p, ['purchase_id' => null, 'inventory_location_id' => 11]);
        $this->seedRow('F-OTHERLOC', $p, ['purchase_id' => null, 'inventory_location_id' => 99]);

        // omit purchase_id entirely — never assume purchase_id=0.
        $names = $this->names(['product_id' => $p, 'inventory_location_id' => 11]);

        sort($names);
        $this->assertSame(['F-A', 'F-B'], $names, 'no purchase-origin restriction when purchase_id is omitted');
    }

    public function test_F_purchase_id_zero_is_treated_as_omitted_not_as_a_real_id(): void
    {
        $p = (int) $this->makeProduct(['code' => 'F2']);
        $this->seedRow('F2-A', $p, ['purchase_id' => 3]);

        $names = $this->names(['purchase_id' => 0, 'product_id' => $p]);

        $this->assertSame(['F2-A'], $names, 'purchase_id=0 never narrows to "no purchase" — it is explicit-omission-safe');
    }

    // ===================== status + availability still enforced ===========

    public function test_only_available_status_is_ever_returned(): void
    {
        $p = (int) $this->makeProduct(['code' => 'ST1']);
        $this->seedRow('ST-AVAIL', $p, ['purchase_id' => 1]);
        $this->seedRow('ST-SOLD', $p, ['purchase_id' => 1, 'status' => ProductSerial::STATUS_SOLD]);

        $names = $this->names(['purchase_id' => 1, 'product_id' => $p]);

        $this->assertSame(['ST-AVAIL'], $names);
    }
}
