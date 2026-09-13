<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('central')->hasTable('landing_refund_policy')) {
            return;
        }

        Schema::connection('central')->create('landing_refund_policy', function (Blueprint $table) {
            $table->id();
            $table->json('translations')->nullable();
            $table->text('overview')->nullable();
            $table->text('subscriptions_trials')->nullable();
            $table->text('cancellations')->nullable();
            $table->text('billing_errors')->nullable();
            $table->text('refund_eligibility')->nullable();
            $table->text('chargebacks')->nullable();
            $table->text('how_to_request')->nullable();
            $table->text('payment_processor')->nullable();
            $table->date('last_updated')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('landing_refund_policy');
    }
};
