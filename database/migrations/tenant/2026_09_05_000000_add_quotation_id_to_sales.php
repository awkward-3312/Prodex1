<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cotización -> Venta POS traceability (Option A).
 *
 * Adds sales.quotation_id — a direct, additive pointer to the source
 * Quotation for a sale created by processing a quotation through the POS
 * (Cotización -> "Procesar en POS" -> PosController@CreatePOS).
 *
 * - nullable: the vast majority of sales have no source quotation.
 * - UNIQUE (nullable): a quotation converts to a sale exactly once. MySQL
 *   allows many NULLs in a UNIQUE index, so this only blocks a *second*
 *   conversion of the same quotation — the DB-level guarantee behind the
 *   server-side check in CreatePOS.
 * - no hard FK: matches the project convention for sales.* pointers
 *   (warehouse_id, branch_id, inventory_location_id, cash_drawer_id,
 *   subscription_id are all bare indexed integers).
 *
 * Type mirrors quotations.id (INT UNSIGNED AUTO_INCREMENT, from
 * `$table->integer('id', true)`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        if (! Schema::hasColumn('sales', 'quotation_id')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->unsignedInteger('quotation_id')->nullable()->after('subscription_id');
            });
        }

        // Add the nullable-unique index only if it is not already there.
        $indexes = $this->indexNames('sales');
        if (! in_array('sales_quotation_id_unique', $indexes, true)) {
            Schema::table('sales', function (Blueprint $table) {
                $table->unique('quotation_id', 'sales_quotation_id_unique');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales') || ! Schema::hasColumn('sales', 'quotation_id')) {
            return;
        }

        $indexes = $this->indexNames('sales');
        if (in_array('sales_quotation_id_unique', $indexes, true)) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropUnique('sales_quotation_id_unique');
            });
        }

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('quotation_id');
        });
    }

    private function indexNames(string $table): array
    {
        try {
            $conn = Schema::getConnection();
            $sm = method_exists($conn, 'getDoctrineSchemaManager') ? $conn->getDoctrineSchemaManager() : null;
            if ($sm) {
                return array_keys($sm->listTableIndexes($table));
            }
        } catch (\Throwable $e) {
            // fall through to the raw query
        }

        try {
            $rows = Schema::getConnection()->select("SHOW INDEX FROM `{$table}`");

            return array_values(array_unique(array_map(fn ($r) => $r->Key_name, $rows)));
        } catch (\Throwable $e) {
            return [];
        }
    }
};
