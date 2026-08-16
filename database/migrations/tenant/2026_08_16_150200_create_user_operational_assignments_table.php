<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_operational_assignments')) {
            return;
        }

        Schema::create('user_operational_assignments', function (Blueprint $table) {
            $table->integer('id', true);
            $table->unsignedInteger('user_id')->index();
            $table->unsignedInteger('default_warehouse_id_snapshot')->nullable();
            $table->string('default_warehouse_name_snapshot')->nullable();
            $table->unsignedInteger('default_cash_drawer_id_snapshot')->nullable();
            $table->string('default_cash_drawer_name_snapshot')->nullable();
            $table->unsignedInteger('temporary_warehouse_id')->index();
            $table->string('temporary_warehouse_name_snapshot')->nullable();
            $table->unsignedInteger('temporary_cash_drawer_id')->index();
            $table->string('temporary_cash_drawer_name_snapshot')->nullable();
            $table->unsignedInteger('assigned_by_user_id')->index();
            $table->string('assigned_by_user_name_snapshot')->nullable();
            $table->dateTime('starts_at')->index();
            $table->dateTime('ends_at')->nullable()->index();
            $table->text('reason')->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_operational_assignments');
    }
};
