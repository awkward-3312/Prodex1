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

        return array_values(array_unique($missing));
    }
}
