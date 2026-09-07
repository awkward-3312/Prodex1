<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Esquema SQLite mínimo para los tests de servicio de
 * {@see \App\Services\Reports\ValuedKardexReportService} y
 * {@see \App\Services\Reports\InventoryTurnoverReportService}.
 *
 * Crea sólo las columnas que esos servicios y `InventoryReadService` leen. Todo
 * el stock de estos fixtures vive en `product_warehouse` (legacy);
 * `inventory_transition_states` se deja vacío → InventoryReadService trata cada
 * almacén como legacy (transición legacy → moderna).
 */
trait InventoryMovementReportSchema
{
    protected function buildInventoryReportSchema(): void
    {
        Schema::create('branches', function ($t) {
            $t->increments('id');
            $t->string('name');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('warehouses', function ($t) {
            $t->increments('id');
            $t->integer('branch_id')->nullable();
            $t->string('name');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('categories', function ($t) {
            $t->increments('id');
            $t->string('name');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('units', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->string('ShortName')->nullable();
            $t->string('operator')->default('*');
            $t->float('operator_value')->default(1);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('products', function ($t) {
            $t->increments('id');
            $t->string('type')->default('is_single');
            $t->string('code')->nullable();
            $t->string('name');
            $t->decimal('cost', 15, 4)->default(0);
            $t->decimal('price', 15, 4)->default(0);
            $t->integer('category_id')->nullable();
            $t->integer('unit_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('product_variants', function ($t) {
            $t->increments('id');
            $t->integer('product_id');
            $t->string('name')->nullable();
            $t->decimal('cost', 15, 4)->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('product_warehouse', function ($t) {
            $t->increments('id');
            $t->integer('product_id');
            $t->integer('warehouse_id');
            $t->integer('product_variant_id')->nullable();
            $t->decimal('qte', 15, 3)->default(0);
            $t->boolean('manage_stock')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('inventory_location_stocks', function ($t) {
            $t->increments('id');
            $t->integer('inventory_location_id');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->integer('variant_key')->default(0);
            $t->decimal('quantity', 15, 3)->default(0);
            $t->timestamps();
        });
        Schema::create('inventory_transition_states', function ($t) {
            $t->increments('id');
            $t->integer('warehouse_id');
            $t->integer('inventory_location_id')->nullable();
            $t->string('mode')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });

        $header = function ($t) {
            $t->increments('id');
            $t->date('date')->nullable();
            $t->string('time')->nullable();
            $t->string('Ref')->nullable();
            $t->integer('warehouse_id')->nullable();
            $t->string('statut')->nullable();
            $t->timestamps();
            $t->softDeletes();
        };
        Schema::create('purchases', $header);
        Schema::create('sales', function ($t) use ($header) {
            $header($t);
            $t->integer('branch_id')->nullable();
        });
        Schema::create('sale_returns', function ($t) use ($header) {
            $header($t);
            $t->integer('branch_id')->nullable();
        });
        Schema::create('purchase_returns', $header);
        Schema::create('adjustments', $header);
        Schema::create('damages', $header);
        Schema::create('transfers', function ($t) {
            $t->increments('id');
            $t->date('date')->nullable();
            $t->string('time')->nullable();
            $t->string('Ref')->nullable();
            $t->integer('from_warehouse_id')->nullable();
            $t->integer('to_warehouse_id')->nullable();
            $t->string('statut')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('purchase_details', function ($t) {
            $t->increments('id');
            $t->integer('purchase_id');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->decimal('cost', 15, 4)->default(0);
            $t->decimal('quantity', 15, 3)->default(0);
            $t->integer('purchase_unit_id')->nullable();
            $t->timestamps();
        });
        Schema::create('purchase_return_details', function ($t) {
            $t->increments('id');
            $t->integer('purchase_return_id');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->decimal('cost', 15, 4)->default(0);
            $t->decimal('quantity', 15, 3)->default(0);
            $t->integer('purchase_unit_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('sale_details', function ($t) {
            $t->increments('id');
            $t->date('date')->nullable();
            $t->integer('sale_id');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->decimal('quantity', 15, 3)->default(0);
            $t->integer('sale_unit_id')->nullable();
            $t->timestamps();
        });
        Schema::create('sale_return_details', function ($t) {
            $t->increments('id');
            $t->integer('sale_return_id');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->decimal('quantity', 15, 3)->default(0);
            $t->integer('sale_unit_id')->nullable();
            $t->timestamps();
        });
        Schema::create('adjustment_details', function ($t) {
            $t->increments('id');
            $t->integer('adjustment_id');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->decimal('quantity', 15, 3)->default(0);
            $t->string('type')->default('add');
            $t->timestamps();
        });
        Schema::create('damage_details', function ($t) {
            $t->increments('id');
            $t->integer('damage_id');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->decimal('quantity', 15, 3)->default(0);
            $t->timestamps();
        });
        Schema::create('transfer_details', function ($t) {
            $t->increments('id');
            $t->integer('transfer_id');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->decimal('cost', 15, 4)->default(0);
            $t->decimal('quantity', 15, 3)->default(0);
            $t->integer('purchase_unit_id')->nullable();
            $t->timestamps();
        });
    }

    protected function branch(string $name): int
    {
        return DB::table('branches')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function warehouse(string $name, ?int $branchId = null): int
    {
        return DB::table('warehouses')->insertGetId(['name' => $name, 'branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function product(string $name, float $cost, array $extra = []): int
    {
        return DB::table('products')->insertGetId(array_merge([
            'name' => $name, 'code' => strtoupper(substr($name, 0, 4)), 'cost' => $cost,
            'type' => 'is_single', 'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    protected function onHand(int $productId, int $warehouseId, float $qty, ?int $variantId = null): void
    {
        DB::table('product_warehouse')->insert([
            'product_id' => $productId, 'warehouse_id' => $warehouseId,
            'product_variant_id' => $variantId, 'qte' => $qty,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function purchase(int $warehouseId, string $date, array $lines, string $statut = 'received'): int
    {
        $id = DB::table('purchases')->insertGetId([
            'date' => $date, 'time' => '10:00:00', 'Ref' => 'PU-'.uniqid(),
            'warehouse_id' => $warehouseId, 'statut' => $statut, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($lines as $l) {
            DB::table('purchase_details')->insert([
                'purchase_id' => $id, 'product_id' => $l['product_id'],
                'product_variant_id' => $l['variant_id'] ?? null,
                'cost' => $l['cost'], 'quantity' => $l['qty'],
                'purchase_unit_id' => $l['unit_id'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    protected function sale(int $warehouseId, string $date, array $lines, ?int $branchId = null, string $statut = 'completed'): int
    {
        $id = DB::table('sales')->insertGetId([
            'date' => $date, 'time' => '11:00:00', 'Ref' => 'SL-'.uniqid(),
            'warehouse_id' => $warehouseId, 'branch_id' => $branchId, 'statut' => $statut,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($lines as $l) {
            DB::table('sale_details')->insert([
                'date' => $date, 'sale_id' => $id, 'product_id' => $l['product_id'],
                'product_variant_id' => $l['variant_id'] ?? null,
                'quantity' => $l['qty'], 'sale_unit_id' => $l['unit_id'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    protected function saleReturn(int $warehouseId, string $date, array $lines, ?int $branchId = null): int
    {
        $id = DB::table('sale_returns')->insertGetId([
            'date' => $date, 'time' => '12:00:00', 'Ref' => 'SR-'.uniqid(),
            'warehouse_id' => $warehouseId, 'branch_id' => $branchId, 'statut' => 'received',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($lines as $l) {
            DB::table('sale_return_details')->insert([
                'sale_return_id' => $id, 'product_id' => $l['product_id'],
                'product_variant_id' => $l['variant_id'] ?? null,
                'quantity' => $l['qty'], 'sale_unit_id' => $l['unit_id'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    protected function adjustment(int $warehouseId, string $date, array $lines): int
    {
        $id = DB::table('adjustments')->insertGetId([
            'date' => $date, 'time' => '13:00:00', 'Ref' => 'AD-'.uniqid(),
            'warehouse_id' => $warehouseId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($lines as $l) {
            DB::table('adjustment_details')->insert([
                'adjustment_id' => $id, 'product_id' => $l['product_id'],
                'product_variant_id' => $l['variant_id'] ?? null,
                'quantity' => $l['qty'], 'type' => $l['type'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    protected function damage(int $warehouseId, string $date, array $lines): int
    {
        $id = DB::table('damages')->insertGetId([
            'date' => $date, 'time' => '14:00:00', 'Ref' => 'DM-'.uniqid(),
            'warehouse_id' => $warehouseId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($lines as $l) {
            DB::table('damage_details')->insert([
                'damage_id' => $id, 'product_id' => $l['product_id'],
                'product_variant_id' => $l['variant_id'] ?? null,
                'quantity' => $l['qty'], 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    protected function transfer(int $fromWh, int $toWh, string $date, array $lines): int
    {
        $id = DB::table('transfers')->insertGetId([
            'date' => $date, 'time' => '15:00:00', 'Ref' => 'TR-'.uniqid(),
            'from_warehouse_id' => $fromWh, 'to_warehouse_id' => $toWh, 'statut' => 'completed',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($lines as $l) {
            DB::table('transfer_details')->insert([
                'transfer_id' => $id, 'product_id' => $l['product_id'],
                'product_variant_id' => $l['variant_id'] ?? null,
                'cost' => $l['cost'] ?? 0, 'quantity' => $l['qty'],
                'purchase_unit_id' => $l['unit_id'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }
}
