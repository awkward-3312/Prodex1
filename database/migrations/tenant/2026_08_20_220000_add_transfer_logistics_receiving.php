<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            if (! Schema::hasColumn('transfers', 'receiving_token')) {
                $table->string('receiving_token', 80)->nullable()->unique()->after('approval_status');
            }
            if (! Schema::hasColumn('transfers', 'logistics_status')) {
                $table->string('logistics_status', 40)->default('pending')->index()->after('receiving_token');
            }
            if (! Schema::hasColumn('transfers', 'dispatched_at')) {
                $table->timestamp('dispatched_at')->nullable()->after('logistics_status');
            }
            if (! Schema::hasColumn('transfers', 'dispatched_by_user_id')) {
                $table->integer('dispatched_by_user_id')->nullable()->index()->after('dispatched_at');
            }
            if (! Schema::hasColumn('transfers', 'received_at')) {
                $table->timestamp('received_at')->nullable()->after('dispatched_by_user_id');
            }
            if (! Schema::hasColumn('transfers', 'received_by_user_id')) {
                $table->integer('received_by_user_id')->nullable()->index()->after('received_at');
            }
        });

        if (! Schema::hasTable('transfer_receipts')) {
            Schema::create('transfer_receipts', function (Blueprint $table) {
                $table->id();
                $table->integer('transfer_id')->index();
                $table->integer('warehouse_id')->index();
                $table->integer('received_by_user_id')->index();
                $table->string('status', 32)->default('partial');
                $table->text('notes')->nullable();
                $table->timestamp('received_at');
                $table->timestamps();

                $table->foreign('transfer_id')->references('id')->on('transfers')->onDelete('cascade');
                $table->foreign('warehouse_id')->references('id')->on('warehouses')->onDelete('restrict');
                $table->foreign('received_by_user_id')->references('id')->on('users')->onDelete('restrict');
            });
        }

        if (! Schema::hasTable('transfer_receipt_items')) {
            Schema::create('transfer_receipt_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('transfer_receipt_id')->index();
                $table->integer('transfer_detail_id')->index();
                $table->decimal('quantity_good', 20, 6)->default(0);
                $table->decimal('quantity_defective', 20, 6)->default(0);
                $table->decimal('quantity_missing', 20, 6)->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->foreign('transfer_receipt_id')->references('id')->on('transfer_receipts')->onDelete('cascade');
                $table->foreign('transfer_detail_id')->references('id')->on('transfer_details')->onDelete('cascade');
            });
        }

        if (! Schema::hasTable('transfer_discrepancies')) {
            Schema::create('transfer_discrepancies', function (Blueprint $table) {
                $table->id();
                $table->integer('transfer_id')->index();
                $table->integer('transfer_detail_id')->index();
                $table->integer('warehouse_id')->index();
                $table->integer('reported_by_user_id')->index();
                $table->string('type', 24); // missing | defective
                $table->decimal('quantity', 20, 6);
                $table->string('resolution_status', 24)->default('open')->index();
                $table->text('notes')->nullable();
                $table->timestamp('reported_at');
                $table->timestamp('resolved_at')->nullable();
                $table->integer('resolved_by_user_id')->nullable()->index();
                $table->timestamps();

                $table->foreign('transfer_id')->references('id')->on('transfers')->onDelete('cascade');
                $table->foreign('transfer_detail_id')->references('id')->on('transfer_details')->onDelete('cascade');
                $table->foreign('warehouse_id')->references('id')->on('warehouses')->onDelete('restrict');
                $table->foreign('reported_by_user_id')->references('id')->on('users')->onDelete('restrict');
            });
        }

        if (! Schema::hasTable('transfer_quarantine_stock')) {
            Schema::create('transfer_quarantine_stock', function (Blueprint $table) {
                $table->id();
                $table->integer('transfer_id')->index();
                $table->integer('transfer_detail_id')->index();
                $table->integer('warehouse_id')->index();
                $table->integer('product_id')->index();
                $table->integer('product_variant_id')->nullable()->index();
                $table->decimal('quantity', 20, 6);
                $table->string('status', 24)->default('quarantined')->index();
                $table->text('notes')->nullable();
                $table->integer('created_by_user_id')->index();
                $table->timestamps();

                $table->foreign('transfer_id')->references('id')->on('transfers')->onDelete('cascade');
                $table->foreign('transfer_detail_id')->references('id')->on('transfer_details')->onDelete('cascade');
                $table->foreign('warehouse_id')->references('id')->on('warehouses')->onDelete('restrict');
                $table->foreign('product_id')->references('id')->on('products')->onDelete('restrict');
            });
        }

        if (! Schema::hasTable('transfer_events')) {
            Schema::create('transfer_events', function (Blueprint $table) {
                $table->id();
                $table->integer('transfer_id')->index();
                $table->string('event_type', 40)->index();
                $table->integer('actor_user_id')->nullable()->index();
                $table->integer('warehouse_id')->nullable()->index();
                $table->json('payload')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('transfer_id')->references('id')->on('transfers')->onDelete('cascade');
            });
        }

        if (! Schema::hasTable('transfer_notifications')) {
            Schema::create('transfer_notifications', function (Blueprint $table) {
                $table->id();
                $table->integer('transfer_id')->index();
                $table->integer('user_id')->index();
                $table->string('type', 40)->default('incoming_transfer')->index();
                $table->string('title', 180);
                $table->text('message');
                $table->timestamp('read_at')->nullable()->index();
                $table->timestamps();

                $table->unique(['transfer_id', 'user_id', 'type'], 'transfer_notif_unique');
                $table->foreign('transfer_id')->references('id')->on('transfers')->onDelete('cascade');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        // Dedicated receiving permission. Existing roles that already manage transfers
        // receive it once for backward-compatible rollout; admins can later separate it.
        if (Schema::hasTable('permissions')) {
            $permissionId = DB::table('permissions')->where('name', 'transfer_receive')->value('id');
            if (! $permissionId) {
                $permissionId = DB::table('permissions')->insertGetId([
                    'name' => 'transfer_receive',
                    'label' => 'Recibir transferencias de stock',
                    'description' => 'Permite confirmar físicamente la recepción de transferencias destinadas a las bodegas asignadas al usuario.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if (Schema::hasTable('permission_role')) {
                $editPermissionId = DB::table('permissions')->where('name', 'transfer_edit')->value('id');
                if ($editPermissionId) {
                    $roleIds = DB::table('permission_role')->where('permission_id', $editPermissionId)->pluck('role_id');
                    foreach ($roleIds as $roleId) {
                        DB::table('permission_role')->updateOrInsert(
                            ['permission_id' => $permissionId, 'role_id' => $roleId],
                            []
                        );
                    }
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permission_role') && Schema::hasTable('permissions')) {
            $permissionId = DB::table('permissions')->where('name', 'transfer_receive')->value('id');
            if ($permissionId) {
                DB::table('permission_role')->where('permission_id', $permissionId)->delete();
                DB::table('permissions')->where('id', $permissionId)->delete();
            }
        }

        Schema::dropIfExists('transfer_notifications');
        Schema::dropIfExists('transfer_events');
        Schema::dropIfExists('transfer_quarantine_stock');
        Schema::dropIfExists('transfer_discrepancies');
        Schema::dropIfExists('transfer_receipt_items');
        Schema::dropIfExists('transfer_receipts');

        foreach (['receiving_token', 'logistics_status', 'dispatched_at', 'dispatched_by_user_id', 'received_at', 'received_by_user_id'] as $column) {
            if (Schema::hasColumn('transfers', $column)) {
                Schema::table('transfers', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
