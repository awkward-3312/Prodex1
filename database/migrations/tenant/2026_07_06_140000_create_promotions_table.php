<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->string('name', 192);
            $table->string('code', 64)->nullable()->index();
            $table->text('description')->nullable();

            // 'discount' = unconditional reduction, 'promotion' = conditional offer.
            $table->enum('kind', ['discount', 'promotion'])->default('discount')->index();

            // How the value is applied.
            $table->enum('discount_type', ['percentage', 'fixed'])->default('percentage');
            $table->decimal('discount_value', 12, 2)->default(0);

            $table->boolean('is_active')->default(true)->index();

            // Validity window.
            $table->dateTime('starts_at')->nullable()->index();
            $table->dateTime('ends_at')->nullable()->index();
            $table->time('time_of_day_start')->nullable();
            $table->time('time_of_day_end')->nullable();

            // Conditions.
            $table->decimal('min_cart_total', 14, 2)->nullable();
            $table->unsignedInteger('min_item_count')->nullable();
            $table->enum('product_scope', ['all', 'specific'])->default('all');

            // Stacking rules: highest priority wins; if stackable=true, multiple may apply.
            $table->integer('priority')->default(0)->index();
            $table->boolean('stackable')->default(false);

            $table->timestamps(6);
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::drop('promotions');
    }
};
