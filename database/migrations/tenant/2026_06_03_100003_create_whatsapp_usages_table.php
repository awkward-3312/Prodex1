<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Monthly WhatsApp message counter, used to enforce per-plan quotas.
     * One row per calendar month (period = "YYYY-MM").
     */
    public function up(): void
    {
        Schema::create('whatsapp_usages', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->bigIncrements('id');
            $table->string('period', 7)->unique(); // YYYY-MM
            $table->unsignedInteger('sent_count')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_usages');
    }
};
