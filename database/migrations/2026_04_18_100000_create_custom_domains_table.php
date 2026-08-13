<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('custom_domains', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('domain', 255)->unique();
            $table->string('verification_token', 64);
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_check_at')->nullable();
            $table->string('last_check_error', 500)->nullable();
            $table->enum('ssl_status', ['pending', 'active', 'failed', 'unknown'])->default('pending');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->foreign('tenant_id')
                ->references('id')->on('tenants')
                ->onUpdate('cascade')
                ->onDelete('cascade');

            $table->index(['tenant_id', 'is_verified']);
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('custom_domains');
    }
};
