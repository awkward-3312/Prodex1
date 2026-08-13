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
        Schema::create('clients', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->unsignedBigInteger('woocommerce_id')->nullable();
            $table->string('sync_issue_type', 64)->nullable();
            $table->text('sync_issue_message')->nullable();
            $table->string('sync_issue_source', 32)->nullable();
            $table->dateTime('sync_issue_at')->nullable();
            $table->boolean('is_royalty_eligible')->default(true);
            $table->float('points')->default(0);
            $table->decimal('opening_balance', 15)->default(0);
            $table->decimal('credit_limit', 15)->default(0);
            $table->string('name');
            $table->string('firstname')->nullable();
            $table->string('lastname')->nullable();
            $table->integer('code');
            $table->string('email')->nullable();
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('state', 100)->nullable();
            $table->string('zip', 20)->nullable();
            $table->string('phone')->nullable();
            $table->string('tax_number', 192)->nullable();
            $table->string('adresse')->nullable();
            $table->string('quickbooks_id', 64)->nullable()->index();
            $table->timestamps(6);
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
