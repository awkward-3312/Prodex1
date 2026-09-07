<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The fiscal correlativo belongs to the AUTHORIZATION / FISCAL SERIES, never to
 * a cash drawer or a branch.
 *
 *   Fiscal series  = sar_points_of_issue (establishment_code + point_code)
 *   Authorisation  = sar_authorizations  (one CAI + range + deadline + next_number)
 *   Coverage       = sar_point_cash_drawers  (series -> the cash drawers allowed
 *                    to consume it; a drawer maps to at most one series)
 *
 * A series can hold, at the same time:
 *   - one `active` authorisation  (the CAI currently issuing numbers)
 *   - one `prepared` authorisation (the next CAI, already registered, that PRODEX
 *     switches to automatically and atomically when the active one runs out)
 *   - any number of `exhausted` / `expired` / `disabled` / `draft` rows (history)
 *
 * This migration only ADDS: the `prepared` status value, an `activated_at`
 * audit stamp, a `superseded_by_id` link for the transition trail, and a
 * resolution index. Nothing in sar_fiscal_documents / correlativos / existing
 * CAI rows is modified; existing `active` / `draft` authorisations keep working
 * unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sar_authorizations')) {
            return;
        }

        // 1) widen the status enum so `prepared` is a legal value.
        $this->allowPreparedStatus();

        // 2) audit columns for the automatic current -> next transition.
        Schema::table('sar_authorizations', function (Blueprint $table) {
            if (! Schema::hasColumn('sar_authorizations', 'activated_at')) {
                $table->timestamp('activated_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('sar_authorizations', 'exhausted_at')) {
                $table->timestamp('exhausted_at')->nullable()->after('activated_at');
            }
            if (! Schema::hasColumn('sar_authorizations', 'superseded_by_id')) {
                $table->unsignedBigInteger('superseded_by_id')->nullable()->after('exhausted_at');
            }
        });

        // 3) index that the number service uses to lock a series' authorisations.
        $this->addIndexIfMissing(
            'sar_authorizations',
            ['point_of_issue_id', 'document_type', 'status'],
            'sar_authorizations_series_status_index'
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('sar_authorizations')) {
            return;
        }

        $this->dropIndexIfExists('sar_authorizations', 'sar_authorizations_series_status_index');

        Schema::table('sar_authorizations', function (Blueprint $table) {
            foreach (['superseded_by_id', 'exhausted_at', 'activated_at'] as $column) {
                if (Schema::hasColumn('sar_authorizations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        // Leave the enum widened: narrowing it is unsafe if `prepared` rows exist.
        // Any leftover `prepared` row is harmless — it is simply never resolved
        // as usable by the old code path.
    }

    /**
     * `status` was created as ENUM('draft','active','exhausted','expired','disabled').
     * Add 'prepared'. On non-MySQL drivers (SQLite test DB) the column is plain
     * text and already accepts the value, so this is a no-op there.
     */
    private function allowPreparedStatus(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        try {
            DB::statement(
                "ALTER TABLE `sar_authorizations` MODIFY `status` "
                ."ENUM('draft','active','prepared','exhausted','expired','disabled') NOT NULL DEFAULT 'draft'"
            );
        } catch (\Throwable $e) {
            // Column is already wide enough, or not an enum on this database.
        }
    }

    private function addIndexIfMissing(string $table, array $columns, string $indexName): void
    {
        if ($this->indexExists($table, $indexName)) {
            return;
        }
        try {
            Schema::table($table, fn (Blueprint $t) => $t->index($columns, $indexName));
        } catch (\Throwable $e) {
            // Index already present under a different name, or driver limitation.
        }
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if ($this->indexExists($table, $indexName)) {
            try {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($indexName));
            } catch (\Throwable $e) {
                // best effort
            }
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
