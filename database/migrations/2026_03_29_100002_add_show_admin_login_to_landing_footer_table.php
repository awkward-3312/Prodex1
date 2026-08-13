<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddShowAdminLoginToLandingFooterTable extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('landing_footer', function (Blueprint $table) {
            $table->boolean('show_admin_login')->default(false)->after('youtube');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('landing_footer', function (Blueprint $table) {
            $table->dropColumn('show_admin_login');
        });
    }
}
