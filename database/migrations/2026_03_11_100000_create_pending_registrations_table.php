<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePendingRegistrationsTable extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('pending_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->string('subdomain');
            $table->string('company_name');
            $table->string('admin_email');
            $table->string('admin_password_hash');
            $table->unsignedBigInteger('plan_id');
            $table->string('billing_cycle')->default('monthly');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('status')->default('pending');
            $table->string('gateway')->nullable();
            $table->string('gateway_session_id')->nullable();
            $table->string('gateway_payment_id')->nullable();
            $table->string('transaction_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('tenant_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('plan_id')->references('id')->on('plans');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('pending_registrations');
    }
}
