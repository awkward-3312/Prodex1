<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use App\Models\UserOperationalAssignment;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BranchScopeService
{
    public function allowedBranchIds(User $user): array
    {
        if ((int) $user->role_id === 1) {
            return $this->allActiveBranchIds();
        }

        $explicit = $this->explicitBranchIds($user);
        $temporary = $this->temporaryBranchId($user);

        if ($explicit) {
            if ($temporary) $explicit[] = $temporary;
            return $this->activeUnique($explicit);
        }

        $fallback = [];
        if ($user->default_branch_id) $fallback[] = (int) $user->default_branch_id;
        if (optional($user->employee)->branch_id) $fallback[] = (int) $user->employee->branch_id;
        if ($temporary) $fallback[] = $temporary;

        if ($fallback) {
            return $this->activeUnique($fallback);
        }

        // Transitional compatibility only. Existing accounts have warehouse scope
        // but no branch rows yet. Once a user has explicit branch scope, warehouse
        // ownership no longer controls which branches they can access.
        if ((int) $user->is_all_warehouses === 1) {
            return $this->allActiveBranchIds();
        }

        if (Schema::hasTable('user_warehouse') && Schema::hasColumn('warehouses', 'branch_id')) {
            return Warehouse::whereNull('deleted_at')
                ->whereIn('id', DB::table('user_warehouse')->where('user_id', $user->id)->pluck('warehouse_id'))
                ->whereNotNull('branch_id')
                ->pluck('branch_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        return [];
    }

    public function canAccess(User $user, int $branchId): bool
    {
        return in_array($branchId, $this->allowedBranchIds($user), true);
    }

    private function explicitBranchIds(User $user): array
    {
        if (! Schema::hasTable('user_branches')) return [];

        return DB::table('user_branches')
            ->where('user_id', $user->id)
            ->pluck('branch_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function temporaryBranchId(User $user): ?int
    {
        if (! Schema::hasTable('user_operational_assignments')
            || ! Schema::hasColumn('user_operational_assignments', 'temporary_branch_id')) {
            return null;
        }

        $assignment = UserOperationalAssignment::where('user_id', $user->id)
            ->where('status', UserOperationalAssignment::STATUS_ACTIVE)
            ->whereNotNull('temporary_branch_id')
            ->where('starts_at', '<=', now())
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->first(['temporary_branch_id']);

        return $assignment?->temporary_branch_id ? (int) $assignment->temporary_branch_id : null;
    }

    private function allActiveBranchIds(): array
    {
        return Branch::whereNull('deleted_at')->where('is_active', true)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function activeUnique(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)));
        if (! $ids) return [];

        return Branch::whereNull('deleted_at')->where('is_active', true)->whereIn('id', $ids)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
