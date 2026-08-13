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
        Schema::create('assets', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->bigIncrements('id');
            $table->string('tag')->unique();
            $table->string('name');
            $table->unsignedBigInteger('asset_category_id')->nullable()->index();
            $table->string('serial_number')->nullable();
            $table->text('description')->nullable();
            $table->date('purchase_date')->nullable();
            $table->double('purchase_cost')->nullable();
            $table->string('status')->default('in_use');
            $table->unsignedBigInteger('warehouse_id')->nullable()->index();
            $table->unsignedBigInteger('assigned_to_id')->nullable();
            $table->date('last_verification')->nullable();
            $table->date('next_validation')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['name', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
