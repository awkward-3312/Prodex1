<?php

namespace App\Services\Mobile;

use App\Exceptions\Mobile\MobilePosPreflightException;
use App\Http\Controllers\PosCashRegisterController;
use App\Models\CashRegister;
use App\Models\CashRegisterOperation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class MobileCashRegisterCloseService
{
    public function __construct(private MobileCashRegisterSessionResolver $sessions, private PosCashRegisterController $engine) {}

    public function close(User $user, array $payload): array
    {
        $uuid = strtolower($payload['operation_uuid']);
        unset($payload['operation_uuid']);
        $payload['register_id'] = (int) $payload['register_id'];
        foreach (['counted_cash', 'closing_balance', 'cash_withdrawn_at_close', 'next_opening_float', 'card_terminal_total'] as $field) {
            $value = $payload[$field] ?? null;
            $parts = $value === null ? null : explode('.', $value);
            $payload[$field] = $parts === null ? null : $parts[0].'.'.str_pad($parts[1] ?? '', 2, '0');
        }
        foreach (['card_batch_number', 'card_reference', 'card_notes', 'transfer_notes', 'notes'] as $field) {
            $payload[$field] = isset($payload[$field]) ? trim($payload[$field]) : null;
        }
        $payload['transfers_verified'] = (bool) ($payload['transfers_verified'] ?? false);
        $payload['counted_denominations'] = isset($payload['counted_denominations']) ? array_map('intval', $payload['counted_denominations']) : null;
        if (is_array($payload['counted_denominations'])) ksort($payload['counted_denominations']);
        ksort($payload);
        $fingerprint = hash('sha256', json_encode(['type' => 'close', 'user_id' => $user->id, 'payload' => $payload]));

        try {
            return DB::transaction(function () use ($user, $uuid, $payload, $fingerprint) {
                if ($saved = $this->existing($user, $uuid, $fingerprint)) return $this->result($saved, true);
                $register = CashRegister::whereKey($payload['register_id'])->lockForUpdate()->first();
                // A simultaneous close may have committed while we waited for the register lock.
                if ($saved = $this->existing($user, $uuid, $fingerprint)) return $this->result($saved, true);
                if (! $register) throw new MobilePosPreflightException('register_not_found', 404);
                $this->sessions->assertContext($user, $register);
                if ($register->status !== 'open') throw new MobilePosPreflightException('register_already_closed', 409);

                $this->engine->applyClosing($register, $payload, $user);
                // Do not catch uniqueness errors within the transaction: the close must roll back too.
                $operation = CashRegisterOperation::create([
                    'operation_uuid' => $uuid, 'cash_register_id' => $register->id, 'user_id' => $user->id,
                    'operation_type' => 'close', 'amount' => $payload['counted_cash'],
                    'notes' => $payload['notes'], 'payload_fingerprint' => $fingerprint, 'source' => 'mobile',
                ]);
                $operation->setRelation('cashRegister', $register);
                return $this->result($operation, false);
            }, 3);
        } catch (QueryException $error) {
            if (! in_array((string) $error->getCode(), ['23000', '23505'], true)) throw $error;
            $saved = $this->existing($user, $uuid, $fingerprint);
            if (! $saved) throw $error;
            return $this->result($saved, true);
        }
    }

    private function existing(User $user, string $uuid, string $fingerprint): ?CashRegisterOperation
    {
        $operation = CashRegisterOperation::with('cashRegister')->where('operation_uuid', $uuid)->lockForUpdate()->first();
        if (! $operation) return null;
        if ((int) $operation->user_id !== (int) $user->id || $operation->operation_type !== 'close'
            || ! hash_equals($operation->payload_fingerprint, $fingerprint)) {
            throw new MobilePosPreflightException('idempotency_conflict', 409);
        }
        return $operation;
    }

    private function result(CashRegisterOperation $operation, bool $idempotent): array
    {
        $register = $operation->cashRegister;
        return [
            'success' => true, 'idempotent' => $idempotent,
            'operation' => ['operation_uuid' => $operation->operation_uuid, 'operation_type' => 'close'],
            'register' => ['id' => $register->id, 'status' => $register->status, 'closed_at' => $register->closed_at?->toIso8601String()],
            'summary' => $register->closing_snapshot,
        ];
    }
}
