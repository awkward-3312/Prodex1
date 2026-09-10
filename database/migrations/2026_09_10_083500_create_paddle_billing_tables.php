<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('paddle_checkout_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->string('tenant_id', 64)->index();
            $table->unsignedBigInteger('plan_id')->index();
            $table->unsignedBigInteger('tenant_subscription_id')->nullable()->index();
            $table->string('billing_cycle', 16);
            $table->string('status', 24)->default('initiated')->index();
            $table->string('paddle_subscription_id', 64)->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();

            $table->foreign('plan_id')
                ->references('id')
                ->on('plans')
                ->cascadeOnDelete();
            $table->foreign('tenant_subscription_id')
                ->references('id')
                ->on('tenant_subscriptions')
                ->nullOnDelete();
        });

        Schema::connection('central')->create('paddle_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 64)->index();
            $table->unsignedBigInteger('tenant_subscription_id')->unique();
            $table->string('paddle_subscription_id', 64)->unique();
            $table->string('paddle_customer_id', 64)->nullable()->index();
            $table->string('paddle_price_id', 64)->nullable()->index();
            $table->string('status', 32)->index();
            $table->timestamp('next_billed_at')->nullable();
            $table->timestamp('current_period_starts_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->json('scheduled_change')->nullable();
            $table->json('custom_data')->nullable();
            $table->timestamp('last_event_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('tenant_subscription_id')
                ->references('id')
                ->on('tenant_subscriptions')
                ->cascadeOnDelete();
        });

        Schema::connection('central')->create('paddle_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 64)->unique();
            $table->string('event_type', 100)->index();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->char('payload_hash', 64);
            $table->string('status', 24)->default('processing')->index();
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('paddle_webhook_events');
        Schema::connection('central')->dropIfExists('paddle_subscriptions');
        Schema::connection('central')->dropIfExists('paddle_checkout_attempts');
    }
};
