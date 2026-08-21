<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_batches') && ! Schema::hasTable('product_batch_location_stocks')) {
            Schema::create('product_batch_location_stocks', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('product_batch_id')->index();
                $table->integer('inventory_location_id')->index();
                $table->decimal('quantity', 12, 3)->default(0);
                $table->decimal('reserved_quantity', 12, 3)->default(0);
                $table->timestamps(6);
                $table->unique(
                    ['product_batch_id', 'inventory_location_id'],
                    'product_batch_location_unique'
                );
            });
        }

        if (Schema::hasTable('product_serials') && ! Schema::hasColumn('product_serials', 'inventory_location_id')) {
            Schema::table('product_serials', function (Blueprint $table) {
                $table->integer('inventory_location_id')->nullable()->index();
            });
        }

        if (Schema::hasTable('product_serial_movements')) {
            Schema::table('product_serial_movements', function (Blueprint $table) {
                if (! Schema::hasColumn('product_serial_movements', 'from_inventory_location_id')) {
                    $table->integer('from_inventory_location_id')->nullable()->index();
                }
                if (! Schema::hasColumn('product_serial_movements', 'to_inventory_location_id')) {
                    $table->integer('to_inventory_location_id')->nullable()->index();
                }
            });
        }

        $this->backfillBatchLocations();
        $this->backfillSerialLocations();
    }

    private function backfillBatchLocations(): void
    {
        if (! Schema::hasTable('product_batch_location_stocks')
            || ! Schema::hasTable('product_batches')
            || ! Schema::hasTable('warehouses')
            || ! Schema::hasColumn('warehouses', 'default_inventory_location_id')) {
            return;
        }

        DB::table('product_batches')
            ->whereNull('deleted_at')
            ->whereNotNull('warehouse_id')
            ->orderBy('id')
            ->chunkById(250, function ($batches) {
                foreach ($batches as $batch) {
                    $locationId = DB::table('warehouses')
                        ->where('id', $batch->warehouse_id)
                        ->whereNull('deleted_at')
                        ->value('default_inventory_location_id');

                    if (! $locationId) continue;

                    DB::table('product_batch_location_stocks')->updateOrInsert(
                        [
                            'product_batch_id' => $batch->id,
                            'inventory_location_id' => $locationId,
                        ],
                        [
                            'quantity' => max(0, (float) ($batch->qty ?? 0)),
                            'reserved_quantity' => 0,
                            'created_at' => $batch->created_at ?? now(),
                            'updated_at' => now(),
                        ]
                    );
                }
            }, 'id');
    }

    private function backfillSerialLocations(): void
    {
        if (! Schema::hasTable('product_serials')
            || ! Schema::hasColumn('product_serials', 'inventory_location_id')
            || ! Schema::hasTable('warehouses')
            || ! Schema::hasColumn('warehouses', 'default_inventory_location_id')) {
            return;
        }

        DB::table('product_serials')
            ->whereNull('inventory_location_id')
            ->whereNotNull('warehouse_id')
            ->orderBy('id')
            ->chunkById(250, function ($serials) {
                foreach ($serials as $serial) {
                    $locationId = DB::table('warehouses')
                        ->where('id', $serial->warehouse_id)
                        ->whereNull('deleted_at')
                        ->value('default_inventory_location_id');

                    if ($locationId) {
                        DB::table('product_serials')
                            ->where('id', $serial->id)
                            ->whereNull('inventory_location_id')
                            ->update([
                                'inventory_location_id' => $locationId,
                                'updated_at' => now(),
                            ]);
                    }
                }
            }, 'id');
    }

    public function down(): void
    {
        if (Schema::hasTable('product_serial_movements')) {
            $columns = array_values(array_filter([
                Schema::hasColumn('product_serial_movements', 'to_inventory_location_id') ? 'to_inventory_location_id' : null,
                Schema::hasColumn('product_serial_movements', 'from_inventory_location_id') ? 'from_inventory_location_id' : null,
            ]));
            if ($columns) Schema::table('product_serial_movements', fn (Blueprint $table) => $table->dropColumn($columns));
        }

        if (Schema::hasTable('product_serials') && Schema::hasColumn('product_serials', 'inventory_location_id')) {
            Schema::table('product_serials', fn (Blueprint $table) => $table->dropColumn('inventory_location_id'));
        }

        Schema::dropIfExists('product_batch_location_stocks');
    }
};
