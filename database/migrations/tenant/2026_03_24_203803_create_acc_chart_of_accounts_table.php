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
        Schema::create('acc_chart_of_accounts', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->integer('account_id')->nullable()->index();
            $table->string('code', 64);
            $table->string('name', 192);
            $table->string('type', 32)->index();
            $table->integer('parent_id')->nullable()->index();
            $table->smallInteger('level')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('acc_chart_of_accounts');
    }
};
