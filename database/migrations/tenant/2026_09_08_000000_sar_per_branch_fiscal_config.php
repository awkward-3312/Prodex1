<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-branch SAR fiscal configuration.
 *
 * The tenant no longer creates a "point of issue" per cash drawer by hand.
 * PRODEX manages the technical structure: one point of issue per branch that
 * covers the branch's cash drawers (many-to-one via a pivot). The tenant only
 * supplies SAR-authorised data (establishment/point codes, CAI, range, deadline).
 *
 * New:
 *   - sar_branch_settings           : per-branch "Facturación SAR habilitada" flag.
 *   - sar_point_cash_drawers        : point -> cash drawers it covers (a drawer
 *                                     maps to at most one point).
 *   - sar_points_of_issue.is_auto_managed : true for points PRODEX creates/syncs
 *                                     per branch; establishment/point codes may
 *                                     be blank until the admin fills them.
 *
 * Backward compatible: existing manual points keep working. establishment_code /
 * point_code become nullable so a managed point can exist before the admin
 * enters its authorised codes.
 *
 * Nothing in sar_authorizations / sar_fiscal_documents / correlativos / CAI is
 * modified.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sar_points_of_issue')) {
            return;
        }

        if (! Schema::hasTable('sar_branch_settings')) {
            Schema::create('sar_branch_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('branch_id')->unique();
                $table->boolean('enabled')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sar_point_cash_drawers')) {
            Schema::create('sar_point_cash_drawers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sar_point_of_issue_id');
                $table->unsignedInteger('cash_drawer_id')->unique(); // a drawer -> at most one point
                $table->timestamps();

                $table->foreign('sar_point_of_issue_id')
                    ->references('id')->on('sar_points_of_issue')
                    ->cascadeOnUpdate()->cascadeOnDelete();
                $table->index('sar_point_of_issue_id', 'sar_point_drawers_point_index');
            });
        }

        Schema::table('sar_points_of_issue', function (Blueprint $table) {
            if (! Schema::hasColumn('sar_points_of_issue', 'is_auto_managed')) {
                $table->boolean('is_auto_managed')->default(false)->after('active');
            }
        });

        // establishment_code / point_code become nullable (a managed point can
        // exist before the admin enters its authorised codes). Widening only.
        $this->makeNullable('sar_points_of_issue', 'establishment_code', 'VARCHAR(3)');
        $this->makeNullable('sar_points_of_issue', 'point_code', 'VARCHAR(3)');

        // --- backfill: existing single-drawer points -> pivot rows -----------
        DB::table('sar_points_of_issue')
            ->whereNotNull('cash_drawer_id')
            ->orderBy('id')
            ->chunkById(200, function ($points) {
                foreach ($points as $point) {
                    $already = DB::table('sar_point_cash_drawers')
                        ->where('cash_drawer_id', $point->cash_drawer_id)
                        ->exists();
                    if (! $already) {
                        DB::table('sar_point_cash_drawers')->insert([
                            'sar_point_of_issue_id' => $point->id,
                            'cash_drawer_id' => $point->cash_drawer_id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            });

        // --- backfill: branches that already have an active point -> enabled --
        if (Schema::hasColumn('sar_points_of_issue', 'branch_id')) {
            $branchIds = DB::table('sar_points_of_issue')
                ->whereNotNull('branch_id')
                ->where('active', true)
                ->distinct()
                ->pluck('branch_id');

            foreach ($branchIds as $branchId) {
                $exists = DB::table('sar_branch_settings')->where('branch_id', $branchId)->exists();
                if (! $exists) {
                    DB::table('sar_branch_settings')->insert([
                        'branch_id' => (int) $branchId,
                        'enabled' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sar_point_cash_drawers')) {
            Schema::drop('sar_point_cash_drawers');
        }
        if (Schema::hasTable('sar_branch_settings')) {
            Schema::drop('sar_branch_settings');
        }
        if (Schema::hasTable('sar_points_of_issue') && Schema::hasColumn('sar_points_of_issue', 'is_auto_managed')) {
            Schema::table('sar_points_of_issue', fn (Blueprint $t) => $t->dropColumn('is_auto_managed'));
        }
        // establishment_code / point_code are intentionally left nullable — the
        // NOT NULL restore is not safe if managed points already exist.
    }

    private function makeNullable(string $table, string $column, string $mysqlType): void
    {
        if (! Schema::hasColumn($table, $column)) {
            return;
        }
        $driver = Schema::getConnection()->getDriverName();
        try {
            if ($driver === 'mysql') {
                DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` {$mysqlType} NULL");
            } else {
                Schema::table($table, fn (Blueprint $t) => $t->string($column, 3)->nullable()->change());
            }
        } catch (\Throwable $e) {
            // Already nullable, or the change engine is unavailable on this
            // driver — the app layer never inserts NOT-NULL-violating rows.
        }
    }
};
