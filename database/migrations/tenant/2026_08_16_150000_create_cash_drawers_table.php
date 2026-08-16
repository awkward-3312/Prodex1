<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cash_drawers')) {
            Schema::create('cash_drawers', function (Blueprint $table) {
                $table->integer('id', true);
                $table->unsignedInteger('warehouse_id')->index();
                $table->string('name', 191);
                $table->string('code', 64)->unique();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps(6);
                $table->softDeletes();
            });
        }

        if (Schema::hasTable('warehouses') && Schema::hasTable('cash_drawers')) {
            $now = now();
            $warehouses = DB::table('warehouses')->whereNull('deleted_at')->get(['id']);
            foreach ($warehouses as $warehouse) {
                $exists = DB::table('cash_drawers')
                    ->where('warehouse_id', $warehouse->id)
                    ->whereNull('deleted_at')
                    ->exists();

                if (! $exists) {
                    DB::table('cash_drawers')->insert([
                        'warehouse_id' => $warehouse->id,
                        'name' => 'Caja 01',
                        'code' => 'WH'.$warehouse->id.'-CAJA-01',
                        'description' => 'Caja física inicial creada por actualización de Prodex.',
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_drawers');
    }
};
