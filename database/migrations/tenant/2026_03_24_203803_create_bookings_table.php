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
        Schema::create('bookings', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->string('Ref', 192)->nullable();
            $table->integer('customer_id')->index('bookings_customer_id');
            $table->integer('product_id')->nullable()->index('bookings_product_id');
            $table->decimal('price', 10)->nullable();
            $table->date('booking_date');
            $table->time('booking_time');
            $table->time('booking_end_time')->nullable();
            $table->string('status', 50)->default('pending');
            $table->text('notes')->nullable();
            $table->string('google_calendar_event_id', 255)->nullable();
            $table->timestamps(6);
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
