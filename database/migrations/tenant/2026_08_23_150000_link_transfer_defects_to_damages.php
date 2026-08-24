<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('damages') || ! Schema::hasTable('damage_details') || ! Schema::hasTable('transfer_quarantine_stock')) {
            return;
        }

        Schema::table('damages', function (Blueprint $table) {
            if (! Schema::hasColumn('damages', 'source_type')) {
                $table->string('source_type', 40)->nullable()->after('notes');
            }
            if (! Schema::hasColumn('damages', 'source_id')) {
                $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            }
            if (! Schema::hasColumn('damages', 'transfer_id')) {
                $table->unsignedBigInteger('transfer_id')->nullable()->after('source_id');
            }
            if (! Schema::hasColumn('damages', 'source_locked')) {
                $table->boolean('source_locked')->default(false)->after('transfer_id');
            }
        });

        $indexes = collect(DB::select('SHOW INDEX FROM damages'))->pluck('Key_name')->unique();
        if (! $indexes->contains('damages_source_unique')) {
            DB::statement('CREATE UNIQUE INDEX damages_source_unique ON damages (source_type, source_id)');
        }
        if (! $indexes->contains('damages_transfer_id_index')) {
            DB::statement('CREATE INDEX damages_transfer_id_index ON damages (transfer_id)');
        }

        // Backfill defects already placed in quarantine. Runtime synchronization is handled
        // by TransferLogisticsService so production does not depend on MySQL TRIGGER privileges.
        DB::table('transfer_quarantine_stock')
            ->where('quantity', '>', 0)
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    if (DB::table('damages')
                        ->where('source_type', 'transfer_quarantine')
                        ->where('source_id', $row->id)
                        ->exists()) {
                        continue;
                    }

                    $createdAt = $row->created_at ? Carbon::parse($row->created_at) : now();
                    $updatedAt = $row->updated_at ? Carbon::parse($row->updated_at) : $createdAt;

                    $damageId = DB::table('damages')->insertGetId([
                        'user_id' => $row->created_by_user_id ?: 1,
                        'date' => $createdAt->toDateString(),
                        'time' => $createdAt->format('H:i:s'),
                        'Ref' => 'TR-DMG-'.$row->transfer_id.'-'.$row->id,
                        'warehouse_id' => $row->warehouse_id,
                        'items' => 1,
                        'notes' => 'Daño registrado automáticamente durante la recepción de una transferencia.',
                        'source_type' => 'transfer_quarantine',
                        'source_id' => $row->id,
                        'transfer_id' => $row->transfer_id,
                        'source_locked' => 1,
                        'created_at' => $createdAt,
                        'updated_at' => $updatedAt,
                    ]);

                    DB::table('damage_details')->insert([
                        'damage_id' => $damageId,
                        'quantity' => $row->quantity,
                        'product_id' => $row->product_id,
                        'product_variant_id' => $row->product_variant_id,
                        'created_at' => $createdAt,
                        'updated_at' => $updatedAt,
                    ]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('damages')) {
            return;
        }

        $indexes = collect(DB::select('SHOW INDEX FROM damages'))->pluck('Key_name')->unique();
        if ($indexes->contains('damages_source_unique')) {
            DB::statement('DROP INDEX damages_source_unique ON damages');
        }
        if ($indexes->contains('damages_transfer_id_index')) {
            DB::statement('DROP INDEX damages_transfer_id_index ON damages');
        }

        Schema::table('damages', function (Blueprint $table) {
            foreach (['source_locked', 'transfer_id', 'source_id', 'source_type'] as $column) {
                if (Schema::hasColumn('damages', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
