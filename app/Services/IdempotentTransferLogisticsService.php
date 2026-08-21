<?php

namespace App\Services;

use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Adds request-level idempotency to physical transfer receiving.
 *
 * A receiver can lose connectivity after PRODEX committed a partial receipt. If
 * the browser retries the POST without an idempotency token, the same boxes could
 * be credited twice. We lock the transfer first, check the token, execute the
 * existing receiving transaction, then attach the token to the newly-created
 * receipt before the outer transaction commits.
 */
class IdempotentTransferLogisticsService extends SafeTransferLogisticsService
{
    public function receive(Transfer $transfer, User $user, array $items, ?string $notes = null, ?string $requestToken = null): Transfer
    {
        if (! $requestToken || ! Schema::hasColumn('transfer_receipts', 'request_token')) {
            return parent::receive($transfer, $user, $items, $notes);
        }

        return DB::transaction(function () use ($transfer, $user, $items, $notes, $requestToken) {
            $locked = Transfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            $existing = DB::table('transfer_receipts')
                ->where('request_token', $requestToken)
                ->first();

            if ($existing) {
                if ((int) $existing->transfer_id !== (int) $locked->id) {
                    throw ValidationException::withMessages([
                        'request_token' => 'El identificador de recepción ya fue utilizado en otra transferencia.',
                    ]);
                }

                // Exact retry: no stock movement, no new receipt, no new discrepancy.
                return Transfer::with(['from_warehouse', 'to_warehouse', 'details.product'])
                    ->findOrFail($locked->id);
            }

            $beforeReceiptId = (int) (DB::table('transfer_receipts')->max('id') ?? 0);
            $updated = parent::receive($locked, $user, $items, $notes);

            $newReceipt = DB::table('transfer_receipts')
                ->where('transfer_id', $locked->id)
                ->where('received_by_user_id', $user->id)
                ->where('id', '>', $beforeReceiptId)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (! $newReceipt) {
                throw ValidationException::withMessages([
                    'transfer' => 'No se pudo identificar el comprobante de recepción creado. La operación fue revertida.',
                ]);
            }

            DB::table('transfer_receipts')
                ->where('id', $newReceipt->id)
                ->update([
                    'request_token' => $requestToken,
                    'updated_at' => now(),
                ]);

            return $updated;
        }, 5);
    }
}
