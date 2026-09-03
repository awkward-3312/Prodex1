<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ============================================================================
 *  MS5-D.1 — inventory_location_id AUTO-SELECTION policy ("rule D2")
 * ============================================================================
 *
 *  A quarantine inventory location is NEVER auto-selected unless an explicit
 *  decision authorises it:
 *
 *    1. persisted document value still valid   -> kept (quarantine included)
 *    2. explicit warehouse default still valid -> selected (quarantine included)
 *    3. linked-purchase suggestion (return)    -> selected only if NOT quarantine
 *    4. sole usable location                   -> selected only if NOT quarantine
 *    5. otherwise                              -> null (user chooses)
 *
 *  The five purchase-family forms delegate the decision to the shared helper
 *  resources/src/utils/inventoryLocationAutoSelect.js so the policy cannot
 *  drift per screen.
 *
 *  Two layers:
 *   - architecture: the helper exists, the policy is is_quarantine-driven (not
 *     name/label/type/position), and every screen delegates to it;
 *   - behaviour: the 14 D2 input/output cases are evaluated by executing the
 *     real helper with Node (skipped, not failed, when Node is unavailable).
 *
 *  Not fragile: pattern / behaviour based, never line numbers.
 */
class PurchaseLocationAutoSelectContractTest extends TestCase
{
    private const HELPER = 'resources/src/utils/inventoryLocationAutoSelect.js';

    private const SCREENS = [
        'resources/src/views/app/pages/purchases/create_purchase.vue',
        'resources/src/views/app/pages/purchases/edit_purchase.vue',
        'resources/src/views/app/pages/purchases/import_purchases.vue',
        'resources/src/views/app/pages/purchase_return/create_purchase_return.vue',
        'resources/src/views/app/pages/purchase_return/edit_purchase_return.vue',
    ];

    /** Screens whose warehouse selector must clear a stale selection first. */
    private const WAREHOUSE_SWITCH_SCREENS = [
        'resources/src/views/app/pages/purchases/create_purchase.vue' => 'Selected_Warehouse(value) {',
        'resources/src/views/app/pages/purchases/edit_purchase.vue' => 'Selected_Warehouse(value) {',
        'resources/src/views/app/pages/purchases/import_purchases.vue' => 'onWarehouseChange(id) {',
    ];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function read(string $rel): string
    {
        return file_get_contents($this->root().'/'.$rel);
    }

    /** Body of `<name>(` up to the matching brace-less next hook — good enough for a slice. */
    private function slice(string $src, string $marker, int $len = 900): string
    {
        $pos = strpos($src, $marker);
        if ($pos === false) {
            $this->fail("marker '{$marker}' not found");
        }

        return substr($src, $pos, $len);
    }

    // =====================================================================
    // ARCHITECTURE — one helper, is_quarantine-driven, every screen delegates
    // =====================================================================

    public function test_helper_exists_and_exports_the_policy_function(): void
    {
        $src = $this->read(self::HELPER);
        $this->assertStringContainsString('function resolveAutoInventoryLocation(', $src);
        $this->assertStringContainsString('module.exports = { resolveAutoInventoryLocation }', $src);
    }

    public function test_helper_decides_quarantine_only_from_the_backend_flag(): void
    {
        $src = $this->read(self::HELPER);

        // The quarantine test is the backend boolean, nothing else.
        $this->assertStringContainsString('is_quarantine', $src);

        // Executable code only — strip block/line comments so the policy prose
        // (which necessarily talks about names and labels) is not searched.
        $code = preg_replace('#/\*.*?\*/#s', '', $src);
        $code = preg_replace('#(^|\s)//.*$#m', '', $code);

        // Never infer quarantine from a label / code / name / type / text match.
        foreach (['.name', '.code', '.label', '.type', "'quarantine'", '"quarantine"', 'toLowerCase', 'indexOf('] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $code,
                "D2 helper must not derive quarantine from `{$needle}` — only the is_quarantine flag"
            );
        }
    }

    public function test_every_purchase_family_screen_delegates_to_the_helper(): void
    {
        foreach (self::SCREENS as $rel) {
            $src = $this->read($rel);
            $this->assertStringContainsString(
                "utils/inventoryLocationAutoSelect",
                $src,
                "{$rel} must import the shared D2 helper"
            );
            $this->assertStringContainsString(
                'resolveAutoInventoryLocation({',
                $src,
                "{$rel} must resolve inventory_location_id through the shared D2 helper"
            );
        }
    }

    public function test_no_screen_keeps_the_old_sole_location_autoselect(): void
    {
        foreach (self::SCREENS as $rel) {
            $src = $this->read($rel);
            // The pre-D2 bug: "the only location" was assigned unconditionally.
            $this->assertStringNotContainsString(
                'ids.length === 1) this.',
                $src,
                "{$rel} still auto-assigns the sole location outside the D2 helper"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/else if \(ids\.length === 1\) \{\s*this\.(purchase|purchase_return)\.inventory_location_id = ids\[0\];/',
                $src,
                "{$rel} still auto-assigns the sole location outside the D2 helper"
            );
        }
    }

    public function test_return_create_passes_linked_purchase_only_as_a_suggestion(): void
    {
        $src = $this->read('resources/src/views/app/pages/purchase_return/create_purchase_return.vue');
        $call = $this->slice($src, 'resolveAutoInventoryLocation({');
        $this->assertStringContainsString('linkedLocationId:', $call);
        $this->assertStringNotContainsString('persistedLocationId:', $call);
    }

    public function test_edit_screens_pass_the_persisted_value_for_validation(): void
    {
        foreach ([
            'resources/src/views/app/pages/purchases/edit_purchase.vue',
            'resources/src/views/app/pages/purchase_return/edit_purchase_return.vue',
        ] as $rel) {
            $src = $this->read($rel);
            $call = $this->slice($src, 'resolveAutoInventoryLocation({');
            $this->assertStringContainsString('persistedLocationId:', $call, "{$rel} must let the helper validate the persisted value");
            $this->assertStringNotContainsString('linkedLocationId:', $call);
            // The blind "keep whatever is loaded" early-return is gone.
            $this->assertStringNotContainsString('if (this.purchase.inventory_location_id) return;', $src);
        }
    }

    public function test_warehouse_switch_clears_a_stale_selection_before_reload(): void
    {
        foreach (self::WAREHOUSE_SWITCH_SCREENS as $rel => $handler) {
            $body = $this->slice($this->read($rel), $handler, 700);
            $nullPos = strpos($body, 'inventory_location_id = null');
            $loadPos = strpos($body, 'Load_Inventory_Locations(');
            $this->assertNotFalse($nullPos, "{$rel}::{$handler} must null the selection on warehouse change");
            $this->assertNotFalse($loadPos, "{$rel}::{$handler} must reload locations on warehouse change");
            $this->assertLessThan($loadPos, $nullPos, "{$rel}::{$handler} must clear the stale selection BEFORE reloading");
        }
    }

    // =====================================================================
    // BEHAVIOUR — the 14 D2 cases, run through the real helper via Node
    // =====================================================================

    /**
     * @dataProvider d2Cases
     */
    public function test_d2_decision_matrix(string $label, array $input, $expected): void
    {
        $results = $this->runPolicy([$input]);
        $this->assertSame($expected, $results[0], "D2 case failed: {$label}");
    }

    public static function d2Cases(): array
    {
        $normal = fn (int $id) => ['id' => $id];
        $quar = fn (int $id) => ['id' => $id, 'is_quarantine' => true];

        return [
            // ---- PURCHASE / IMPORT (no persisted, no linked) ----
            '1 · explicit default normal → selected' => [
                '1', ['locations' => [$normal(1), $normal(2)], 'defaultLocationId' => 1], 1,
            ],
            '2 · explicit default quarantine → selected' => [
                '2', ['locations' => [$quar(1), $normal(2)], 'defaultLocationId' => 1], 1,
            ],
            '3 · no default + sole normal → selected' => [
                '3', ['locations' => [$normal(5)]], 5,
            ],
            '4 · no default + sole quarantine → NOT selected' => [
                '4', ['locations' => [$quar(5)]], null,
            ],
            '5 · no default + multiple → NOT selected' => [
                '5', ['locations' => [$normal(1), $normal(2), $normal(3)]], null,
            ],

            // ---- RETURN (linked purchase location present) ----
            '6 · linked normal, no default → selected' => [
                '6', ['locations' => [$normal(1), $normal(2)], 'linkedLocationId' => 2], 2,
            ],
            '7 · linked quarantine, no default → NOT selected' => [
                '7', ['locations' => [$normal(1), $quar(2)], 'linkedLocationId' => 2], null,
            ],
            '8 · linked quarantine == explicit default → selected' => [
                '8', ['locations' => [$normal(1), $quar(2)], 'defaultLocationId' => 2, 'linkedLocationId' => 2], 2,
            ],

            // ---- EDIT (persisted value present) ----
            '9 · persisted valid normal → preserved' => [
                '9', ['locations' => [$normal(1), $normal(2)], 'persistedLocationId' => 2], 2,
            ],
            '10 · persisted valid quarantine → preserved' => [
                '10', ['locations' => [$normal(1), $quar(2)], 'persistedLocationId' => 2], 2,
            ],
            '11 · persisted invalid → cleared and D2 recalculated' => [
                '11', ['locations' => [$normal(1), $normal(3)], 'persistedLocationId' => 2, 'defaultLocationId' => 3], 3,
            ],

            // ---- WAREHOUSE ----
            '12 · stale (old-warehouse) selection → dropped' => [
                '12', ['locations' => [$normal(7), $normal(8)], 'persistedLocationId' => 99], null,
            ],
            '13 · new warehouse, default quarantine → default selected' => [
                '13', ['locations' => [$quar(1), $normal(2)], 'defaultLocationId' => 1], 1,
            ],
            '14 · new warehouse, sole quarantine, no default → empty' => [
                '14', ['locations' => [$quar(9)]], null,
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Node bridge — execute the real helper, no re-implementation in PHP.
    // ------------------------------------------------------------------

    private function runPolicy(array $cases): array
    {
        $node = $this->nodeBinary();
        if ($node === null) {
            $this->markTestSkipped('Node.js is not available; D2 behaviour matrix skipped (architecture layer still enforced).');
        }

        $script = <<<'JS'
const helperPath = process.argv[1];
const cases = JSON.parse(process.argv[2]);
const { resolveAutoInventoryLocation } = require(helperPath);
const out = cases.map((c) => {
  const r = resolveAutoInventoryLocation(c);
  return r === undefined ? null : r;
});
process.stdout.write(JSON.stringify(out));
JS;

        $cmd = escapeshellarg($node)
            .' -e '.escapeshellarg($script)
            .' '.escapeshellarg($this->root().'/'.self::HELPER)
            .' '.escapeshellarg(json_encode(array_values($cases)))
            .' 2>&1';

        $raw = shell_exec($cmd);
        $this->assertIsString($raw, 'Node produced no output for the D2 matrix');
        $decoded = json_decode(trim($raw), true);
        $this->assertIsArray($decoded, "Node output was not valid JSON: {$raw}");

        return $decoded;
    }

    private function nodeBinary(): ?string
    {
        foreach (['node', 'node.exe'] as $candidate) {
            $which = @shell_exec((stripos(PHP_OS, 'WIN') === 0 ? 'where ' : 'command -v ').escapeshellarg($candidate).' 2>/dev/null');
            if (is_string($which) && trim($which) !== '') {
                return trim(strtok($which, "\n"));
            }
        }

        return null;
    }
}
