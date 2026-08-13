<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('service_job_items', function (Blueprint $table) {
            // Unit cost snapshotted when the line's stock is deducted (at delivery),
            // so historical job profit stays accurate as product costs drift later.
            // Mirrors purchase_details.cost / products.cost typing.
            if (! Schema::hasColumn('service_job_items', 'cost')) {
                $table->decimal('cost', 15)->default(0)->after('unit_price');
            }
        });

        Schema::table('service_jobs', function (Blueprint $table) {
            // Job-level warehouse. The Parts & Labour UI already forces every part line
            // onto one warehouse; persisting it here lets Profit & Loss attribute a job's
            // revenue AND its parts cost to the same warehouse bucket.
            if (! Schema::hasColumn('service_jobs', 'warehouse_id')) {
                $table->unsignedBigInteger('warehouse_id')->nullable()->after('technician_id');
                $table->index('warehouse_id');
            }
        });

        // Backfill warehouse_id on existing jobs from their first part line.
        if (Schema::hasColumn('service_jobs', 'warehouse_id')) {
            DB::table('service_jobs')
                ->whereNull('warehouse_id')
                ->update([
                    'warehouse_id' => DB::raw(
                        '(SELECT sji.warehouse_id
                            FROM service_job_items sji
                           WHERE sji.service_job_id = service_jobs.id
                             AND sji.warehouse_id IS NOT NULL
                             AND sji.deleted_at IS NULL
                        ORDER BY sji.id ASC
                           LIMIT 1)'
                    ),
                ]);
        }
    }

    public function down()
    {
        Schema::table('service_job_items', function (Blueprint $table) {
            if (Schema::hasColumn('service_job_items', 'cost')) {
                $table->dropColumn('cost');
            }
        });

        Schema::table('service_jobs', function (Blueprint $table) {
            if (Schema::hasColumn('service_jobs', 'warehouse_id')) {
                $table->dropIndex(['warehouse_id']);
                $table->dropColumn('warehouse_id');
            }
        });
    }
};
