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
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->increments('id');
            $table->unsignedInteger('commission_program_id')->index();
            $table->string('name');
            $table->string('type', 32)->default('percentage');
            $table->string('source', 32)->default('sale_total');
            $table->decimal('value', 14, 4)->default(0);
            $table->decimal('min_threshold', 14, 4)->nullable();
            $table->decimal('max_cap', 14, 4)->nullable();
            $table->string('applies_to', 32)->default('all_agents');
            $table->unsignedInteger('sales_agent_id')->nullable()->index();
            $table->unsignedTinyInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commission_rules');
    }
};
