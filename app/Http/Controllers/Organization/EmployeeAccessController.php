<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashDrawer;
use App\Models\Employee;
use App\Models\InventoryLocation;
use App\Models\Role;
use App\Models\role_user;
use App\Models\User;
use App\Services\BranchScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeAccessController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeAccess($request);
        $actor = $request->user('api');
        $allowedBranchIds = app(BranchScopeService::class)->allowedBranchIds($actor);

        $employees = Employee::with([
                'branch:id,name,default_inventory_location_id',
                'designation:id,designation,suggested_role_key',
                'user:id,employee_id,firstname,lastname,email,role_id,statut,default_branch_id,default_inventory_location_id,default_cash_drawer_id,is_all_warehouses',
                'user.defaultCashDrawer:id,branch_id,inventory_location_id,name,code,is_active',
            ])
            ->whereNull('deleted_at')
            ->whereNull('leaving_date');

        if ((int) $actor->role_id !== 1) {
            $employees->whereIn('branch_id', $allowedBranchIds ?: [0]);
        }

        $employees = $employees->orderBy('firstname')->orderBy('lastname')
            ->get(['id', 'branch_id', 'designation_id', 'firstname', 'lastname', 'email', 'phone']);

        $unlinkedUsers = User::whereNull('deleted_at')->whereNull('employee_id')->orderBy('firstname')->orderBy('lastname')->get();
        if ((int) $actor->role_id !== 1) {
            $unlinkedUsers = $unlinkedUsers->filter(fn ($candidate) => $this->candidateInsideActorScope($actor, $candidate))->values();
        }

        $branches = Branch::whereNull('deleted_at')->where('is_active', true)->orderBy('name');
        if ((int) $actor->role_id !== 1) $branches->whereIn('id', $allowedBranchIds ?: [0]);
        $branches = $branches->get(['id', 'code', 'name', 'default_inventory_location_id']);

        $branchIds = $branches->pluck('id')->map(fn ($id) => (int) $id)->all();
        $locations = InventoryLocation::active()->whereIn('branch_id', $branchIds ?: [0])
            ->orderBy('branch_id')->orderBy('name')
            ->get(['id', 'branch_id', 'code', 'name', 'type', 'is_sellable', 'is_default_sales']);

        $cashDrawers = CashDrawer::whereNull('deleted_at')
            ->where('is_active', true)
            ->whereIn('branch_id', $branchIds ?: [0])
            ->whereNotNull('inventory_location_id')
            ->orderBy('branch_id')
            ->orderBy('name')
            ->get(['id', 'branch_id', 'inventory_location_id', 'name', 'code', 'is_active']);

        $roles = Role::with('permissions:id,name')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'description'])
            ->map(function (Role $role) {
                $permissions = $role->permissions->pluck('name');
                $usesPos = $permissions->contains('Pos_view');
                $canOverrideDrawer = $permissions->contains('cash_register_override_assignment');

                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'description' => $role->description,
                    'uses_pos' => $usesPos,
                    'requires_cash_drawer' => $usesPos && ! $canOverrideDrawer,
                ];
            })->values();

        return response()->json([
            'employees' => $employees,
            'unlinked_users' => $unlinkedUsers->map(fn ($u) => [
                'id' => $u->id,
                'firstname' => $u->firstname,
                'lastname' => $u->lastname,
                'email' => $u->email,
                'role_id' => $u->role_id,
                'statut' => $u->statut,
            ])->values(),
            'roles' => $roles,
            'branches' => $branches,
            'inventory_locations' => $locations,
            'cash_drawers' => $cashDrawers,
        ]);
    }

    public function create(Request $request, int $employeeId)
    {
        $this->authorizeAccess($request);
        $actor = $request->user('api');
        $employee = $this->employeeForActor($actor, $employeeId, true);

        abort_if(User::where('employee_id', $employee->id)->whereNull('deleted_at')->exists(), 422, 'Este empleado ya tiene una cuenta de acceso.');

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:192', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'max:100'],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where(fn ($q) => $q->whereNull('deleted_at'))],
            'scope' => ['nullable', Rule::in(['branch', 'selected', 'all'])],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer'],
            'inventory_location_ids' => ['nullable', 'array'],
            'inventory_location_ids.*' => ['integer'],
            'default_branch_id' => ['nullable', 'integer'],
            'default_inventory_location_id' => ['nullable', 'integer'],
            'default_cash_drawer_id' => ['nullable', 'integer'],
            'record_view' => ['sometimes', 'boolean'],
        ]);

        $scope = $validated['scope'] ?? ($employee->branch_id ? 'branch' : 'selected');
        if ($scope === 'all' && (int) $actor->role_id !== 1) {
            abort(403, 'Solo el propietario del tenant puede crear accesos con alcance global desde este flujo.');
        }

        $branchIds = $this->resolveBranchScope($employee, $scope, $validated['branch_ids'] ?? []);
        $this->assertActorCanGrantBranches($actor, $branchIds, $scope);

        $locationIds = $this->resolveInventoryLocationScope($scope, $branchIds, $validated['inventory_location_ids'] ?? []);

        $defaultBranchId = ! empty($validated['default_branch_id'])
            ? (int) $validated['default_branch_id']
            : ($scope === 'branch' && $employee->branch_id ? (int) $employee->branch_id : ($branchIds[0] ?? null));

        if ($defaultBranchId && $scope !== 'all' && ! in_array($defaultBranchId, $branchIds, true)) {
            throw ValidationException::withMessages(['default_branch_id' => 'La sucursal predeterminada debe estar dentro del alcance asignado.']);
        }

        $defaultLocationId = ! empty($validated['default_inventory_location_id'])
            ? (int) $validated['default_inventory_location_id']
            : $this->defaultLocationForBranch($defaultBranchId);

        if ($defaultLocationId) {
            $location = InventoryLocation::active()->find($defaultLocationId);
            if (! $location || (int) $location->branch_id !== (int) $defaultBranchId) {
                throw ValidationException::withMessages(['default_inventory_location_id' => 'La ubicación predeterminada debe pertenecer a la sucursal predeterminada.']);
            }
            if ($scope !== 'all' && ! in_array($defaultLocationId, $locationIds, true)) {
                $locationIds[] = $defaultLocationId;
            }
        }

        $roleRequiresDrawer = $this->roleRequiresCashDrawer((int) $validated['role_id']);
        $defaultCashDrawerId = ! empty($validated['default_cash_drawer_id'])
            ? (int) $validated['default_cash_drawer_id']
            : null;

        if ($roleRequiresDrawer && ! $defaultCashDrawerId) {
            throw ValidationException::withMessages([
                'default_cash_drawer_id' => 'Este rol opera POS sin permiso para cambiar de caja. Asigna una caja física predeterminada.',
            ]);
        }

        if ($defaultCashDrawerId) {
            if (! $defaultBranchId || ! $defaultLocationId) {
                throw ValidationException::withMessages([
                    'default_cash_drawer_id' => 'Selecciona primero la sucursal y la ubicación predeterminadas antes de asignar una caja física.',
                ]);
            }

            $drawer = CashDrawer::whereNull('deleted_at')->where('is_active', true)->find($defaultCashDrawerId);
            if (! $drawer
                || (int) $drawer->branch_id !== (int) $defaultBranchId
                || (int) $drawer->inventory_location_id !== (int) $defaultLocationId) {
                throw ValidationException::withMessages([
                    'default_cash_drawer_id' => 'La caja física debe estar activa y pertenecer a la sucursal y ubicación predeterminadas.',
                ]);
            }
        }

        $user = DB::transaction(function () use ($employee, $validated, $scope, $branchIds, $locationIds, $defaultBranchId, $defaultLocationId, $defaultCashDrawerId) {
            $lockedEmployee = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            if (User::where('employee_id', $lockedEmployee->id)->whereNull('deleted_at')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['employee_id' => 'Este empleado ya tiene una cuenta de acceso.']);
            }

            $user = User::create([
                'employee_id' => $lockedEmployee->id,
                'firstname' => $lockedEmployee->firstname,
                'lastname' => $lockedEmployee->lastname,
                'username' => trim($lockedEmployee->firstname.' '.$lockedEmployee->lastname),
                'email' => $validated['email'],
                'phone' => $lockedEmployee->phone,
                'password' => Hash::make($validated['password']),
                'avatar' => random_default_tenant_avatar_filename(),
                'role_id' => (int) $validated['role_id'],
                'statut' => 1,
                // Kept for legacy modules until they migrate away from warehouse scope.
                'is_all_warehouses' => $scope === 'all' ? 1 : 0,
                'default_warehouse_id' => null,
                'default_branch_id' => $defaultBranchId,
                'default_inventory_location_id' => $defaultLocationId,
                'default_cash_drawer_id' => $defaultCashDrawerId,
                'record_view' => (bool) ($validated['record_view'] ?? false),
            ]);

            role_user::create(['user_id' => $user->id, 'role_id' => (int) $validated['role_id']]);

            if ($scope !== 'all') {
                $user->assignedBranches()->sync($branchIds);
                $user->assignedInventoryLocations()->sync(array_values(array_unique($locationIds)));
            }

            return $user;
        });

        return response()->json([
            'success' => true,
            'user' => $user->load([
                'employee.branch:id,name',
                'defaultBranch:id,name',
                'defaultInventoryLocation:id,name',
                'defaultCashDrawer:id,name,code,branch_id,inventory_location_id',
            ]),
        ], 201);
    }

    public function link(Request $request, int $employeeId)
    {
        $this->authorizeAccess($request);
        $actor = $request->user('api');
        $employee = $this->employeeForActor($actor, $employeeId, true);
        $validated = $request->validate(['user_id' => ['required', 'integer']]);

        $candidate = User::whereNull('deleted_at')->findOrFail($validated['user_id']);
        $this->assertCanManageUserScope($actor, $candidate);

        DB::transaction(function () use ($employee, $validated) {
            $lockedEmployee = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $user = User::whereNull('deleted_at')->lockForUpdate()->findOrFail($validated['user_id']);

            if ($user->employee_id && (int) $user->employee_id !== (int) $lockedEmployee->id) {
                throw ValidationException::withMessages(['user_id' => 'Este usuario ya está vinculado a otro empleado.']);
            }
            if (User::where('employee_id', $lockedEmployee->id)->where('id', '!=', $user->id)->exists()) {
                throw ValidationException::withMessages(['employee_id' => 'Este empleado ya tiene una cuenta de acceso vinculada.']);
            }

            $user->update(['employee_id' => $lockedEmployee->id]);
        });

        return response()->json(['success' => true]);
    }

    public function unlink(Request $request, int $employeeId)
    {
        $this->authorizeAccess($request);
        $actor = $request->user('api');
        $employee = $this->employeeForActor($actor, $employeeId, false);
        $linkedUser = User::where('employee_id', $employee->id)->whereNull('deleted_at')->first();
        if ($linkedUser) $this->assertCanManageUserScope($actor, $linkedUser);

        User::where('employee_id', $employee->id)->update(['employee_id' => null]);
        return response()->json(['success' => true]);
    }

    private function resolveBranchScope(Employee $employee, string $scope, array $requested): array
    {
        if ($scope === 'all') return [];
        if ($scope === 'branch') {
            abort_unless($employee->branch_id, 422, 'El empleado no tiene una sucursal asignada.');
            return [(int) $employee->branch_id];
        }

        $requested = array_values(array_unique(array_filter(array_map('intval', $requested), fn ($id) => $id > 0)));
        if (! $requested) return [];

        $existing = Branch::whereNull('deleted_at')->where('is_active', true)->whereIn('id', $requested)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($existing) !== count($requested)) {
            throw ValidationException::withMessages(['branch_ids' => 'Una de las sucursales seleccionadas no existe o está inactiva.']);
        }

        return $requested;
    }

    private function resolveInventoryLocationScope(string $scope, array $branchIds, array $requested): array
    {
        if ($scope === 'all') return [];

        $requested = array_values(array_unique(array_filter(array_map('intval', $requested), fn ($id) => $id > 0)));
        if (! $requested) {
            return Branch::whereIn('id', $branchIds ?: [0])
                ->whereNotNull('default_inventory_location_id')
                ->pluck('default_inventory_location_id')
                ->map(fn ($id) => (int) $id)
                ->unique()->values()->all();
        }

        $existing = InventoryLocation::active()->whereIn('id', $requested)->whereIn('branch_id', $branchIds ?: [0])
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($existing) !== count($requested)) {
            throw ValidationException::withMessages(['inventory_location_ids' => 'Una ubicación seleccionada no pertenece a las sucursales asignadas.']);
        }

        return $requested;
    }

    private function defaultLocationForBranch(?int $branchId): ?int
    {
        if (! $branchId) return null;
        $id = Branch::whereNull('deleted_at')->where('is_active', true)->whereKey($branchId)->value('default_inventory_location_id');
        return $id ? (int) $id : null;
    }

    private function roleRequiresCashDrawer(int $roleId): bool
    {
        $role = Role::with('permissions:id,name')->whereNull('deleted_at')->find($roleId);
        if (! $role) return false;

        $permissions = $role->permissions->pluck('name');
        return $permissions->contains('Pos_view')
            && ! $permissions->contains('cash_register_override_assignment');
    }

    private function assertActorCanGrantBranches(User $actor, array $branchIds, string $scope): void
    {
        if ((int) $actor->role_id === 1 || $scope === 'all') return;
        $allowed = app(BranchScopeService::class)->allowedBranchIds($actor);
        foreach ($branchIds as $branchId) {
            abort_unless(in_array($branchId, $allowed, true), 403, 'No puedes conceder acceso a una sucursal fuera de tu propio alcance.');
        }
    }

    private function employeeForActor(User $actor, int $employeeId, bool $requireActive): Employee
    {
        $query = Employee::with('branch:id,name')->whereNull('deleted_at')->whereKey($employeeId);
        if ($requireActive) $query->whereNull('leaving_date');
        if ((int) $actor->role_id !== 1) {
            $query->whereIn('branch_id', app(BranchScopeService::class)->allowedBranchIds($actor) ?: [0]);
        }
        return $query->firstOrFail();
    }

    private function candidateInsideActorScope(User $actor, User $candidate): bool
    {
        if ((int) $actor->role_id === 1) return true;
        if ((int) $candidate->role_id === 1) return false;

        $actorIds = app(BranchScopeService::class)->allowedBranchIds($actor);
        $candidateIds = app(BranchScopeService::class)->allowedBranchIds($candidate);
        if (! $candidateIds) return false;

        foreach ($candidateIds as $branchId) {
            if (! in_array($branchId, $actorIds, true)) return false;
        }
        return true;
    }

    private function assertCanManageUserScope(User $actor, User $candidate): void
    {
        abort_unless($this->candidateInsideActorScope($actor, $candidate), 403, 'No puedes administrar una cuenta fuera de tu alcance operativo.');
    }

    private function authorizeAccess(Request $request): void
    {
        $user = $request->user('api');
        abort_unless($user, 401);
        abort_unless((int) $user->role_id === 1 || $user->hasPermissionName('users_edit'), 403);
    }
}
