<?php

namespace App\Services;

use Illuminate\Support\Facades\Schema;

class ProdexTenantSchemaHealthService extends TenantSchemaHealthService
{
    public function missingRequirements(): array
    {
        $missing = parent::missingRequirements();
        $schema = Schema::connection('tenant');

        if ($schema->hasTable('product_serials')) {
            if (! $schema->hasTable('transfer_detail_serials')) {
                $missing[] = 'Falta tabla: transfer_detail_serials';
            } else {
                foreach ([
                    'transfer_detail_id', 'product_serial_id', 'transfer_receipt_item_id',
                    'status', 'issue_type', 'received_at',
                ] as $column) {
                    if (! $schema->hasColumn('transfer_detail_serials', $column)) {
                        $missing[] = 'Falta columna: transfer_detail_serials.'.$column;
                    }
                }
            }
        }

        if ($schema->hasTable('product_batches')) {
            if (! $schema->hasTable('transfer_receipt_item_batch_issues')) {
                $missing[] = 'Falta tabla: transfer_receipt_item_batch_issues';
            } else {
                foreach ([
                    'transfer_receipt_item_id', 'transfer_detail_batch_id', 'source_batch_id',
                    'destination_batch_id', 'inventory_location_id', 'issue_type', 'quantity',
                    'resolved_quantity', 'resolution_status', 'resolution_code', 'resolved_at',
                ] as $column) {
                    if (! $schema->hasColumn('transfer_receipt_item_batch_issues', $column)) {
                        $missing[] = 'Falta columna: transfer_receipt_item_batch_issues.'.$column;
                    }
                }
            }
        }

        return array_values(array_unique($missing));
    }
}
