<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('users') || ! Schema::connection('tenant')->hasColumn('users', 'phone')) {
            return;
        }

        DB::connection('tenant')->statement('ALTER TABLE `users` MODIFY `phone` VARCHAR(192) NULL');
    }

    public function down(): void
    {
        if (! Schema::connection('tenant')->hasTable('users') || ! Schema::connection('tenant')->hasColumn('users', 'phone')) {
            return;
        }

        DB::connection('tenant')->table('users')->whereNull('phone')->update(['phone' => '']);
        DB::connection('tenant')->statement("ALTER TABLE `users` MODIFY `phone` VARCHAR(192) NOT NULL");
    }
};
