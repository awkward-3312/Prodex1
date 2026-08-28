<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('business_audit_logs')) {
            return;
        }

        Schema::create('business_audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('actor_name', 191)->nullable();
            $table->string('event', 32)->index();
            $table->string('auditable_type', 191)->index();
            $table->string('auditable_id', 191)->nullable()->index();
            $table->string('reference', 191)->nullable()->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->unsignedBigInteger('inventory_location_id')->nullable()->index();
            $table->unsignedBigInteger('cash_drawer_id')->nullable()->index();
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->string('http_method', 16)->nullable();
            $table->string('request_path', 512)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 768)->nullable();
            $table->timestamp('created_at', 6)->useCurrent()->index();

            $table->index(['auditable_type', 'auditable_id', 'created_at'], 'business_audit_subject_idx');
            $table->index(['user_id', 'created_at'], 'business_audit_user_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_audit_logs');
    }
};
