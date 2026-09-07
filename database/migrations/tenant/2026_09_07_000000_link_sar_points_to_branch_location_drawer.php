<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SAR points of issue must be anchored to the modern POS operational identity:
 *   Branch -> InventoryLocation -> CashDrawer -> SAR Point -> Authorization -> CAI.
 *
 * `warehouse_id` stays on the table only for legacy compatibility (non-POS /
 * pre-location sales). It is no longer the source of truth for POS invoicing.
 *
 * Backfill rule (deliberately conservative — never invents relations from
 * Warehouse):
 *   - A point that already has cash_drawer_id: branch_id / inventory_location_id
 *     are copied from that CashDrawer's current relations (the authoritative
 *     source). This is idempotent and lossless.
 *   - A point without cash_drawer_id (or whose drawer is deleted / branchless)
 *     is left with branch_id / inventory_location_id NULL: it is "pending
 *     configuration" for modern POS but keeps working through the legacy
 *     warehouse_id resolver for the sales that still use it.
 *
 * Nothing in sar_authorizations / sar_fiscal_documents / correlativos / CAI is
 * touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sar_points_of_issue')) {
            return;
        }

        Schema::table('sar_points_of_issue', function (Blueprint $table) {
            if (! Schema::hasColumn('sar_points_of_issue', 'branch_id')) {
                $table->unsignedInteger('branch_id')->nullable()->after('address');
            }
            if (! Schema::hasColumn('sar_points_of_issue', 'inventory_location_id')) {
                $table->unsignedInteger('inventory_location_id')->nullable()->after('branch_id');
            }
        });

        // Indexes (guarded — re-running the migration must not fail).
        $this->addIndexIfMissing('sar_points_of_issue', 'branch_id', 'sar_points_branch_id_index');
        $this->addIndexIfMissing('sar_points_of_issue', 'inventory_location_id', 'sar_points_inventory_location_id_index');
        $this->addIndexIfMissing('sar_points_of_issue', 'cash_drawer_id', 'sar_points_cash_drawer_id_index');

        if (! Schema::hasTable('cash_drawers')) {
            return;
        }

        $hasDrawerBranch = Schema::hasColumn('cash_drawers', 'branch_id');
        $hasDrawerLocation = Schema::hasColumn('cash_drawers', 'inventory_location_id');
        if (! $hasDrawerBranch && ! $hasDrawerLocation) {
            return;
        }

        // Backfill exclusively from CashDrawer, only when unambiguous.
        DB::table('sar_points_of_issue')
            ->whereNotNull('cash_drawer_id')
            ->where(function ($q) {
                $q->whereNull('branch_id')->orWhereNull('inventory_location_id');
            })
            ->orderBy('id')
            ->chunkById(200, function ($points) use ($hasDrawerBranch, $hasDrawerLocation) {
                $drawerIds = $points->pluck('cash_drawer_id')->filter()->unique()->values();
                if ($drawerIds->isEmpty()) {
                    return;
                }

                $cols = ['id'];
                if ($hasDrawerBranch) $cols[] = 'branch_id';
                if ($hasDrawerLocation) $cols[] = 'inventory_location_id';

                $drawers = DB::table('cash_drawers')
                    ->whereIn('id', $drawerIds)
                    ->whereNull('deleted_at')
                    ->get($cols)
                    ->keyBy('id');

                foreach ($points as $point) {
                    $drawer = $drawers->get($point->cash_drawer_id);
                    if (! $drawer) {
                        continue; // drawer missing/deleted -> leave pending configuration
                    }

                    $update = [];
                    if ($point->branch_id === null && $hasDrawerBranch && ! empty($drawer->branch_id)) {
                        $update['branch_id'] = (int) $drawer->branch_id;
                    }
                    if ($point->inventory_location_id === null && $hasDrawerLocation && ! empty($drawer->inventory_location_id)) {
                        $update['inventory_location_id'] = (int) $drawer->inventory_location_id;
                    }

                    if ($update) {
                        $update['updated_at'] = now();
                        DB::table('sar_points_of_issue')->where('id', $point->id)->update($update);
                    }
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sar_points_of_issue')) {
            return;
        }

        $this->dropIndexIfExists('sar_points_of_issue', 'sar_points_branch_id_index');
        $this->dropIndexIfExists('sar_points_of_issue', 'sar_points_inventory_location_id_index');
        $this->dropIndexIfExists('sar_points_of_issue', 'sar_points_cash_drawer_id_index');

        Schema::table('sar_points_of_issue', function (Blueprint $table) {
            foreach (['inventory_location_id', 'branch_id'] as $column) {
                if (Schema::hasColumn('sar_points_of_issue', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function addIndexIfMissing(string $table, string $column, string $indexName): void
    {
        if (! Schema::hasColumn($table, $column) || $this->indexExists($table, $indexName)) {
            return;
        }
        Schema::table($table, fn (Blueprint $t) => $t->index($column, $indexName));
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if ($this->indexExists($table, $indexName)) {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex($indexName));
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        try {
            if (method_exists(Schema::class, 'hasIndex')) {
                return Schema::hasIndex($table, $indexName);
            }
            return in_array(
                $indexName,
                array_map(
                    fn ($i) => $i['name'] ?? null,
                    Schema::getConnection()->getSchemaBuilder()->getIndexes($table)
                ),
                true
            );
        } catch (\Throwable $e) {
            return false;
        }
    }
};
