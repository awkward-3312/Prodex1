<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency + audit ledger for Mobile cash-register writes (open, cash_in, cash_out).
 * Mirrors the idempotency_key/idempotency_fingerprint convention already used by
 * inventory_location_movements/product_serial_movements, but operation_uuid is
 * REQUIRED (every mobile write must freeze one UUID before its first POST) and
 * UNIQUE - the last line of defense against a duplicated financial operation
 * under a concurrent retry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_register_operations', function (Blueprint $table) {
            $table->id();
            $table->char('operation_uuid', 36)->unique();
            $table->unsignedBigInteger('cash_register_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('operation_type', 20); // open | cash_in | cash_out
            $table->decimal('amount', 15, 2);
            $table->string('notes', 255)->nullable();
            $table->string('payload_fingerprint', 64);
            $table->string('source', 20)->default('mobile');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_register_operations');
    }
};
