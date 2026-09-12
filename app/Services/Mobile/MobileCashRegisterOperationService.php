<?php

namespace App\Services\Mobile;

use App\Exceptions\Mobile\MobilePosPreflightException;
use App\Models\CashDrawer;
use App\Models\CashRegister;
use App\Models\CashRegisterOperation;
use App\Models\User;
use App\Services\UserOperationalAssignmentService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Idempotent, transactional writes for Mobile cash-register operations (open,
 * cash_in, cash_out). Mirrors the lockForUpdate + idempotency_fingerprint
 * pattern already proven by InventoryService::transactional() - operation_uuid
 * here is required (never optional) and the DB unique constraint on it is the
 * final defense against a duplicated financial write under a raced retry.
 *
 * Deliberately does NOT delegate to PosCashRegisterController::openRegister()/
 * cashInOut() - wrapping an HTTP controller action in our own transaction+lock
 * would be fragile. The assignment-resolution and register-mutation logic below
 * is intentionally kept equivalent to that controller, not literally shared.
 */
class MobileCashRegisterOperationService
{
    public function __construct(private UserOperationalAssignmentService $assignments) {}

    public function open(User $user, array $payload): array
    {
        $uuid = (string) $payload['operation_uuid'];
        $openingBalance = (string) $payload['opening_balance'];
        $notes = isset($payload['notes']) ? trim((string) $payload['notes']) : null;

        $canonical = ['type' => 'open', 'opening_balance' => $this->normalizeAmount($openingBalance), 'notes' => $notes];
        $fingerprint = $this->fingerprint($canonical);

        return DB::transaction(function () use ($user, $uuid, $openingBalance, $notes, $fingerprint) {
            if ($idempotent = $this->existingOperation($uuid, $fingerprint)) {
                return $this->openResult($idempotent->cashRegister, true, $idempotent);
            }

            $effective = $this->assignments->effectiveAssignment($user);
            $branchId = $effective['branch_id'] ? (int) $effective['branch_id'] : null;
            $locationId = $effective['inventory_location_id'] ? (int) $effective['inventory_location_id'] : null;
            $cashDrawerId = $effective['cash_drawer_id'] ? (int) $effective['cash_drawer_id'] : null;

            if (! $branchId || ! $locationId) {
                throw ValidationException::withMessages(['opening_balance' => 'Tu asignación operativa no tiene sucursal o ubicación configurada.']);
            }

            $warehouseId = $effective['warehouse_id'] ? (int) $effective['warehouse_id'] : null;
            if ($cashDrawerId) {
                $drawer = CashDrawer::whereNull('deleted_at')->find($cashDrawerId);
                if ($drawer && $drawer->warehouse_id) $warehouseId = (int) $drawer->warehouse_id;
            }

            $existingRegister = CashRegister::where('user_id', $user->id)
                ->where('branch_id', $branchId)
                ->where('inventory_location_id', $locationId)
                ->when($cashDrawerId, fn ($q) => $q->where('cash_drawer_id', $cashDrawerId))
                ->when(! $cashDrawerId, fn ($q) => $q->whereNull('cash_drawer_id'))
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if ($existingRegister) {
                throw new MobilePosPreflightException('register_already_open', 409, [], 'Ya tienes una caja abierta.');
            }

            $register = CashRegister::create([
                'user_id' => $user->id,
                'branch_id' => $branchId,
                'inventory_location_id' => $locationId,
                'warehouse_id' => $warehouseId,
                'cash_drawer_id' => $cashDrawerId,
                'opening_balance' => $openingBalance,
                'status' => 'open',
                'opened_at' => Carbon::now(),
                'notes' => $notes,
            ]);

            $operation = $this->recordOperation($uuid, $register->id, $user->id, 'open', $openingBalance, $notes, $fingerprint);

            return $this->openResult($register, false, $operation);
        });
    }

    public function movement(User $user, array $payload): array
    {
        $uuid = (string) $payload['operation_uuid'];
        $registerId = (int) $payload['register_id'];
        $type = (string) $payload['type']; // 'in' | 'out'
        $amount = (string) $payload['amount'];
        $notes = trim((string) $payload['notes']);

        $canonical = ['type' => 'movement', 'register_id' => $registerId, 'movement_type' => $type, 'amount' => $this->normalizeAmount($amount), 'notes' => $notes];
        $fingerprint = $this->fingerprint($canonical);

        return DB::transaction(function () use ($user, $uuid, $registerId, $type, $amount, $notes, $fingerprint) {
            if ($idempotent = $this->existingOperation($uuid, $fingerprint)) {
                return $this->movementResult($idempotent->cashRegister, true, $idempotent);
            }

            $register = CashRegister::where('id', $registerId)->lockForUpdate()->first();
            if (! $register) {
                throw new MobilePosPreflightException('register_not_found', 404, [], 'No encontramos esa caja.');
            }
            if ((int) $register->user_id !== (int) $user->id) {
                throw new MobilePosPreflightException('forbidden', 403, [], 'No puedes operar esta caja.');
            }
            if ($register->status !== 'open') {
                throw new MobilePosPreflightException('register_closed', 409, [], 'Esta caja ya está cerrada.');
            }

            $operationType = $type === 'in' ? 'cash_in' : 'cash_out';
            if ($type === 'in') {
                $register->cash_in = (float) $register->cash_in + (float) $amount;
            } else {
                $register->cash_out = (float) $register->cash_out + (float) $amount;
            }
            $label = $type === 'in' ? 'Entrada' : 'Salida';
            $stamped = '['.Carbon::now()->toDateTimeString().'] '.$label.' de efectivo L. '.number_format((float) $amount, 2).' - '.$notes;
            $register->notes = trim(($register->notes ? $register->notes."\n" : '').$stamped);
            $register->save();

            $operation = $this->recordOperation($uuid, $register->id, $user->id, $operationType, $amount, $notes, $fingerprint);

            return $this->movementResult($register, false, $operation);
        });
    }

    /** Looks up a processed operation by UUID, locking it against concurrent readers; throws 409 on a payload mismatch. */
    private function existingOperation(string $uuid, string $fingerprint): ?CashRegisterOperation
    {
        $existing = CashRegisterOperation::with('cashRegister')->where('operation_uuid', $uuid)->lockForUpdate()->first();
        if (! $existing) return null;

        if (! hash_equals($existing->payload_fingerprint, $fingerprint)) {
            throw new MobilePosPreflightException('idempotency_conflict', 409, [], 'Esta operación ya fue enviada con datos diferentes.');
        }

        return $existing;
    }

    /** The UNIQUE constraint on operation_uuid is the final defense: a race that slips past existingOperation() lands here. */
    private function recordOperation(string $uuid, int $registerId, int $userId, string $type, string $amount, ?string $notes, string $fingerprint): CashRegisterOperation
    {
        try {
            return CashRegisterOperation::create([
                'operation_uuid' => $uuid,
                'cash_register_id' => $registerId,
                'user_id' => $userId,
                'operation_type' => $type,
                'amount' => $amount,
                'notes' => $notes,
                'payload_fingerprint' => $fingerprint,
                'source' => 'mobile',
            ]);
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) throw $e;

            $existing = CashRegisterOperation::with('cashRegister')->where('operation_uuid', $uuid)->first();
            if (! $existing) throw $e;
            if (! hash_equals($existing->payload_fingerprint, $fingerprint)) {
                throw new MobilePosPreflightException('idempotency_conflict', 409, [], 'Esta operación ya fue enviada con datos diferentes.');
            }

            return $existing;
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array((string) $e->getCode(), ['23000', '23505'], true);
    }

    private function normalizeAmount(string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private function fingerprint(array $canonical): string
    {
        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function openResult(CashRegister $register, bool $idempotent, CashRegisterOperation $operation): array
    {
        return ['register' => $register, 'operation' => $operation, 'idempotent' => $idempotent];
    }

    private function movementResult(CashRegister $register, bool $idempotent, CashRegisterOperation $operation): array
    {
        return ['register' => $register, 'operation' => $operation, 'idempotent' => $idempotent];
    }
}
