<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('marketing_segments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('all_customers')->default(false);
            // JSON filter definition: city, last_purchase_from, last_purchase_to,
            // total_purchases_min, total_purchases_max, total_spent_min, total_spent_max
            $table->json('filters')->nullable();
            $table->integer('customers_count')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps(6);
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists('marketing_segments');
    }
};
