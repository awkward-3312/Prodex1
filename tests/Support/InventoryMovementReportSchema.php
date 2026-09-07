<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Esquema SQLite mínimo para los tests de servicio de
 * {@see \App\Services\Reports\ValuedKardexReportService} y
 * {@see \App\Services\Reports\InventoryTurnoverReportService}.
 *
 * Cubre AMBOS mundos de la transición:
 *  · LEGACY  stock en `product_warehouse`, documentos con `warehouse_id`.
 *  · MODERNO documentos con `branch_id` (ventas / dev. venta) o
 *            `inventory_location_id` (compras / dev. compra / ajustes / daños /
 *            traslados). `inventory_transition_states` vacío → InventoryReadService
 *            lee `product_warehouse` para la existencia (el motor moderno de
 *            stock no se puebla en estos fixtures — sólo los DOCUMENTOS modernos).
 */
trait InventoryMovementReportSchema
{
    protected function buildInventoryReportSchema(): void
    {
        Schema::create('users', function ($t) {
            $t->increments('id');
            $t->string('username')->nullable();
            $t->integer('role_id')->default(1);
            $t->tinyInteger('is_all_warehouses')->default(1);
            $t->integer('default_branch_id')->nullable();
            $t->integer('default_inventory_location_id')->nullable();
            $t->integer('default_warehouse_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('branches', function ($t) {
            $t->increments('id');
            $t->string('name');
            $t->boolean('is_active')->default(true);
            $t->integer('default_inventory_location_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('warehouses', function ($t) {
            $t->increments('id');
            $t->integer('branch_id')->nullable();
            $t->string('name');
            $t->integer('default_inventory_location_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('inventory_locations', function ($t) {
            $t->increments('id');
            $t->integer('branch_id')->nullable();
            $t->integer('warehouse_id')->nullable();
            $t->string('code')->nullable();
            $t->string('name');
            $t->boolean('is_active')->default(true);
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

        // Cabeceras de documento. `warehouse_id` + `inventory_location_id` en
        // todas; `branch_id` sólo en ventas / dev. de venta (como en producción).
        $header = function ($t) {
            $t->increments('id');
            $t->date('date')->nullable();
            $t->string('time')->nullable();
            $t->string('Ref')->nullable();
            $t->integer('warehouse_id')->nullable();
            $t->integer('inventory_location_id')->nullable();
            $t->string('statut')->nullable();
            $t->timestamps();
            $t->softDeletes();
        };
        Schema::create('purchases', $header);
        Schema::create('purchase_returns', $header);
        Schema::create('adjustments', $header);
        Schema::create('damages', $header);
        Schema::create('sales', function ($t) use ($header) {
            $header($t);
            $t->integer('branch_id')->nullable();
        });
        Schema::create('sale_returns', function ($t) use ($header) {
            $header($t);
            $t->integer('branch_id')->nullable();
        });
        Schema::create('transfers', function ($t) {
            $t->increments('id');
            $t->date('date')->nullable();
            $t->string('time')->nullable();
            $t->string('Ref')->nullable();
            $t->integer('from_warehouse_id')->nullable();
            $t->integer('to_warehouse_id')->nullable();
            $t->integer('from_inventory_location_id')->nullable();
            $t->integer('to_inventory_location_id')->nullable();
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

    protected function owner(): User
    {
        $id = DB::table('users')->insertGetId([
            'username' => 'owner', 'role_id' => 1, 'is_all_warehouses' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::find($id);
    }

    protected function branch(string $name): int
    {
        return DB::table('branches')->insertGetId(['name' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function warehouse(string $name, ?int $branchId = null): int
    {
        return DB::table('warehouses')->insertGetId(['name' => $name, 'branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function location(string $name, int $branchId, ?int $warehouseId = null): int
    {
        return DB::table('inventory_locations')->insertGetId([
            'name' => $name, 'code' => strtoupper(substr($name, 0, 6)),
            'branch_id' => $branchId, 'warehouse_id' => $warehouseId, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
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

    /**
     * @param  array  $ctx  warehouse_id?, inventory_location_id?, branch_id?, statut?, ref?
     */
    private function insertDoc(string $table, string $date, string $time, array $ctx): int
    {
        return DB::table($table)->insertGetId(array_merge([
            'date' => $date, 'time' => $time, 'Ref' => ($ctx['ref'] ?? strtoupper(substr($table, 0, 2)).'-'.uniqid()),
            'warehouse_id' => $ctx['warehouse_id'] ?? null,
            'inventory_location_id' => $ctx['inventory_location_id'] ?? null,
            'statut' => $ctx['statut'] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ], array_key_exists('branch_id', $ctx) ? ['branch_id' => $ctx['branch_id']] : []));
    }

    protected function purchase($ctx, string $date, array $lines): int
    {
        $ctx = is_array($ctx) ? $ctx : ['warehouse_id' => $ctx];
        $ctx['statut'] ??= 'received';
        $id = $this->insertDoc('purchases', $date, '10:00:00', $ctx);
        foreach ($lines as $l) {
            DB::table('purchase_details')->insert([
                'purchase_id' => $id, 'product_id' => $l['product_id'], 'product_variant_id' => $l['variant_id'] ?? null,
                'cost' => $l['cost'], 'quantity' => $l['qty'], 'purchase_unit_id' => $l['unit_id'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    protected function purchaseReturn($ctx, string $date, array $lines): int
    {
        $ctx = is_array($ctx) ? $ctx : ['warehouse_id' => $ctx];
        $id = $this->insertDoc('purchase_returns', $date, '10:30:00', $ctx);
        foreach ($lines as $l) {
            DB::table('purchase_return_details')->insert([
                'purchase_return_id' => $id, 'product_id' => $l['product_id'], 'product_variant_id' => $l['variant_id'] ?? null,
                'cost' => $l['cost'], 'quantity' => $l['qty'], 'purchase_unit_id' => $l['unit_id'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    protected function sale($ctx, string $date, array $lines, ?int $branchId = null, string $statut = 'completed'): int
    {
        $ctx = is_array($ctx) ? $ctx : ['warehouse_id' => $ctx, 'branch_id' => $branchId];
        $ctx['branch_id'] = array_key_exists('branch_id', $ctx) ? $ctx['branch_id'] : $branchId;
        $ctx['statut'] ??= $statut;
        $id = $this->insertDoc('sales', $date, '11:00:00', $ctx);
        foreach ($lines as $l) {
            DB::table('sale_details')->insert([
                'date' => $date, 'sale_id' => $id, 'product_id' => $l['product_id'], 'product_variant_id' => $l['variant_id'] ?? null,
                'quantity' => $l['qty'], 'sale_unit_id' => $l['unit_id'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    protected function saleReturn($ctx, string $date, array $lines, ?int $branchId = null): int
    {
        $ctx = is_array($ctx) ? $ctx : ['warehouse_id' => $ctx, 'branch_id' => $branchId];
        $ctx['branch_id'] = array_key_exists('branch_id', $ctx) ? $ctx['branch_id'] : $branchId;
        $ctx['statut'] ??= 'received';
        $id = $this->insertDoc('sale_returns', $date, '12:00:00', $ctx);
        foreach ($lines as $l) {
            DB::table('sale_return_details')->insert([
                'sale_return_id' => $id, 'product_id' => $l['product_id'], 'product_variant_id' => $l['variant_id'] ?? null,
                'quantity' => $l['qty'], 'sale_unit_id' => $l['unit_id'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    protected function adjustment($ctx, string $date, array $lines): int
    {
        $ctx = is_array($ctx) ? $ctx : ['warehouse_id' => $ctx];
        $id = $this->insertDoc('adjustments', $date, '13:00:00', $ctx);
        foreach ($lines as $l) {
            DB::table('adjustment_details')->insert([
                'adjustment_id' => $id, 'product_id' => $l['product_id'], 'product_variant_id' => $l['variant_id'] ?? null,
                'quantity' => $l['qty'], 'type' => $l['type'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    protected function damage($ctx, string $date, array $lines): int
    {
        $ctx = is_array($ctx) ? $ctx : ['warehouse_id' => $ctx];
        $id = $this->insertDoc('damages', $date, '14:00:00', $ctx);
        foreach ($lines as $l) {
            DB::table('damage_details')->insert([
                'damage_id' => $id, 'product_id' => $l['product_id'], 'product_variant_id' => $l['variant_id'] ?? null,
                'quantity' => $l['qty'], 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    /**
     * @param  int|array  $from  warehouse id, or ['warehouse_id'=>, 'inventory_location_id'=>]
     * @param  int|array  $to    idem
     */
    protected function transfer($from, $to, string $date, array $lines): int
    {
        $from = is_array($from) ? $from : ['warehouse_id' => $from];
        $to = is_array($to) ? $to : ['warehouse_id' => $to];
        $id = DB::table('transfers')->insertGetId([
            'date' => $date, 'time' => '15:00:00', 'Ref' => 'TR-'.uniqid(),
            'from_warehouse_id' => $from['warehouse_id'] ?? null,
            'to_warehouse_id' => $to['warehouse_id'] ?? null,
            'from_inventory_location_id' => $from['inventory_location_id'] ?? null,
            'to_inventory_location_id' => $to['inventory_location_id'] ?? null,
            'statut' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($lines as $l) {
            DB::table('transfer_details')->insert([
                'transfer_id' => $id, 'product_id' => $l['product_id'], 'product_variant_id' => $l['variant_id'] ?? null,
                'cost' => $l['cost'] ?? 0, 'quantity' => $l['qty'], 'purchase_unit_id' => $l['unit_id'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }
}
