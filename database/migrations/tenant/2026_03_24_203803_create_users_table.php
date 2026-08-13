<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->string('firstname');
            $table->string('lastname');
            $table->string('username', 192);
            $table->string('email', 192);
            $table->string('password');
            $table->rememberToken();
            $table->string('avatar')->nullable();
            $table->string('phone', 192);
            $table->integer('role_id');
            $table->boolean('statut')->default(true);
            $table->boolean('is_all_warehouses')->default(false);
            $table->boolean('record_view')->default(false);
            $table->timestamps(6);
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
