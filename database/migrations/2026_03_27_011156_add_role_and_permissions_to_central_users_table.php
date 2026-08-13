<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('central_users', function (Blueprint $table) {
            $table->string('role', 32)->default('super_admin')->after('avatar');
            $table->json('permissions')->nullable()->after('role');
        });

        // Existing admins become super_admin (full access)
        DB::connection('central')->table('central_users')->update(['role' => 'super_admin']);
    }

    public function down(): void
    {
        Schema::connection('central')->table('central_users', function (Blueprint $table) {
            $table->dropColumn(['role', 'permissions']);
        });
    }
};
