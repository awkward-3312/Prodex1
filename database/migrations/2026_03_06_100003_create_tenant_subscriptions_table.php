<?php

declare(strict_types=1);

/**
 * Central migration: tenant subscription to a plan (SaaS billing, not product subscriptions).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTenantSubscriptionsTable extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('tenant_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->unsignedBigInteger('plan_id');
            $table->string('status')->default('active'); // active, suspended, cancelled, trial
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('plan_id')->references('id')->on('plans')->onUpdate('cascade')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('tenant_subscriptions');
    }
}
