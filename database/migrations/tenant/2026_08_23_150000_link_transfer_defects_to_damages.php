<?php

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

        // Backfill defects already placed in transfer quarantine before this migration.
        DB::table('transfer_quarantine_stock')
            ->where('quantity', '>', 0)
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $exists = DB::table('damages')
                        ->where('source_type', 'transfer_quarantine')
                        ->where('source_id', $row->id)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    $damageId = DB::table('damages')->insertGetId([
                        'user_id' => $row->created_by_user_id ?: 1,
                        'date' => optional($row->created_at)->format('Y-m-d') ?: now()->toDateString(),
                        'time' => optional($row->created_at)->format('H:i:s') ?: now()->toTimeString(),
                        'Ref' => 'TR-DMG-'.$row->transfer_id.'-'.$row->id,
                        'warehouse_id' => $row->warehouse_id,
                        'items' => 1,
                        'notes' => 'Daño registrado automáticamente durante la recepción de una transferencia.',
                        'source_type' => 'transfer_quarantine',
                        'source_id' => $row->id,
                        'transfer_id' => $row->transfer_id,
                        'source_locked' => 1,
                        'created_at' => $row->created_at ?: now(),
                        'updated_at' => $row->updated_at ?: now(),
                    ]);

                    DB::table('damage_details')->insert([
                        'damage_id' => $damageId,
                        'quantity' => $row->quantity,
                        'product_id' => $row->product_id,
                        'product_variant_id' => $row->product_variant_id,
                        'created_at' => $row->created_at ?: now(),
                        'updated_at' => $row->updated_at ?: now(),
                    ]);
                }
            });

        DB::unprepared('DROP TRIGGER IF EXISTS prodex_transfer_quarantine_to_damage');
        DB::unprepared('DROP TRIGGER IF EXISTS prodex_lock_transfer_damage_update');
        DB::unprepared('DROP TRIGGER IF EXISTS prodex_lock_transfer_damage_delete');

        // Defective units are already excluded from sellable destination stock and live in
        // transfer_quarantine_stock. This trigger creates the audit/document record only;
        // it intentionally does NOT subtract product_warehouse again.
        DB::unprepared(<<<'SQL'
CREATE TRIGGER prodex_transfer_quarantine_to_damage
AFTER INSERT ON transfer_quarantine_stock
FOR EACH ROW
BEGIN
    DECLARE v_damage_id BIGINT;

    IF NEW.quantity > 0 AND NOT EXISTS (
        SELECT 1
        FROM damages
        WHERE source_type = 'transfer_quarantine'
          AND source_id = NEW.id
        LIMIT 1
    ) THEN
        INSERT INTO damages (
            user_id, date, time, Ref, warehouse_id, items, notes,
            source_type, source_id, transfer_id, source_locked,
            created_at, updated_at
        ) VALUES (
            COALESCE(NEW.created_by_user_id, 1),
            CURRENT_DATE(),
            CURRENT_TIME(),
            CONCAT('TR-DMG-', NEW.transfer_id, '-', NEW.id),
            NEW.warehouse_id,
            1,
            'Daño registrado automáticamente durante la recepción de una transferencia.',
            'transfer_quarantine',
            NEW.id,
            NEW.transfer_id,
            1,
            COALESCE(NEW.created_at, CURRENT_TIMESTAMP(6)),
            COALESCE(NEW.updated_at, CURRENT_TIMESTAMP(6))
        );

        SET v_damage_id = LAST_INSERT_ID();

        INSERT INTO damage_details (
            damage_id, quantity, product_id, product_variant_id, created_at, updated_at
        ) VALUES (
            v_damage_id,
            NEW.quantity,
            NEW.product_id,
            NEW.product_variant_id,
            COALESCE(NEW.created_at, CURRENT_TIMESTAMP(6)),
            COALESCE(NEW.updated_at, CURRENT_TIMESTAMP(6))
        );
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER prodex_lock_transfer_damage_update
BEFORE UPDATE ON damages
FOR EACH ROW
BEGIN
    IF OLD.source_locked = 1 AND OLD.source_type = 'transfer_quarantine' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Los daños generados por recepción de transferencias son documentos logísticos inmutables.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER prodex_lock_transfer_damage_delete
BEFORE DELETE ON damages
FOR EACH ROW
BEGIN
    IF OLD.source_locked = 1 AND OLD.source_type = 'transfer_quarantine' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Los daños generados por recepción de transferencias no pueden eliminarse.';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('damages')) {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS prodex_transfer_quarantine_to_damage');
        DB::unprepared('DROP TRIGGER IF EXISTS prodex_lock_transfer_damage_update');
        DB::unprepared('DROP TRIGGER IF EXISTS prodex_lock_transfer_damage_delete');

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
