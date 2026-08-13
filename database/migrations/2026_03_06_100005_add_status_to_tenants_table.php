<?php

declare(strict_types=1);

/**
 * Central: tenant status (active, suspended, cancelled).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddStatusToTenantsTable extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('tenants', function (Blueprint $table) {
            $table->string('status', 32)->default('active')->after('id');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('tenants', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
}
