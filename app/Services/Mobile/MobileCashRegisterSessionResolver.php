<?php

namespace App\Services\Mobile;

use App\Exceptions\Mobile\MobilePosPreflightException;
use App\Models\CashRegister;
use App\Models\User;
use App\Services\UserOperationalAssignmentService;

class MobileCashRegisterSessionResolver
{
    public function __construct(private UserOperationalAssignmentService $assignments) {}

    public function current(User $user, bool $lock = false, ?array $context = null): ?CashRegister
    {
        $context ??= $this->assignments->effectiveAssignment($user);
        if (empty($context['branch_id']) || empty($context['inventory_location_id'])) return null;
        $query = CashRegister::where('user_id', $user->id)->where('status', 'open');
        foreach (['branch_id', 'inventory_location_id', 'cash_drawer_id'] as $field) {
            $query->where($field, ($context[$field] ?? null) ?: null);
        }
        if ($lock) $query->lockForUpdate();
        return $query->orderByDesc('id')->first();
    }

    public function requireOpen(User $user, bool $lock = false, ?array $context = null): CashRegister
    {
        return $this->current($user, $lock, $context)
            ?? throw new MobilePosPreflightException('cash_register_not_open', 409, [], 'Necesitas abrir caja antes de registrar una venta.');
    }

    public function assertContext(User $user, CashRegister $register): void
    {
        if ((int) $register->user_id !== (int) $user->id) {
            throw new MobilePosPreflightException('forbidden', 403);
        }
        $context = $this->assignments->effectiveAssignment($user);
        if (empty($context['branch_id']) || empty($context['inventory_location_id'])) {
            throw new MobilePosPreflightException('cash_register_not_open', 409);
        }
        foreach (['branch_id', 'inventory_location_id', 'cash_drawer_id'] as $field) {
            if (($register->$field ?: null) != (($context[$field] ?? null) ?: null)) {
                throw new MobilePosPreflightException('cash_register_not_open', 409);
            }
        }
    }
}
