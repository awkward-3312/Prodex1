<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Role;
use App\Models\role_user;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\WarehouseScopeService;
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

        $employees = Employee::with([
                'branch:id,name',
                'designation:id,designation,suggested_role_key',
                'user:id,employee_id,firstname,lastname,email,role_id,statut,default_warehouse_id,is_all_warehouses',
            ])
            ->whereNull('deleted_at')
            ->whereNull('leaving_date');

        if ((int) $actor->is_all_warehouses !== 1) {
            $employees->whereIn('branch_id', $this->allowedBranchIds($actor) ?: [0]);
        }

        $employees = $employees->orderBy('firstname')->orderBy('lastname')
            ->get(['id', 'branch_id', 'designation_id', 'firstname', 'lastname', 'email', 'phone']);

        $unlinkedUsers = User::whereNull('deleted_at')->whereNull('employee_id');
        if ((int) $actor->is_all_warehouses !== 1) {
            $allowed = app(WarehouseScopeService::class)->allowedWarehouseIds($actor);
            $unlinkedUsers->where('is_all_warehouses', 0)
                ->whereHas('assignedWarehouses', fn ($q) => $q->whereIn('warehouses.id', $allowed ?: [0]));
        }

        return response()->json([
            'employees' => $employees,
            'unlinked_users' => $unlinkedUsers->orderBy('firstname')->orderBy('lastname')->get(['id', 'firstname', 'lastname', 'email', 'role_id', 'statut']),
            'roles' => Role::whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'description']),
            'warehouses' => app(WarehouseScopeService::class)->visibleWarehouses($actor)->map(function ($warehouse) {
                $warehouse->loadMissing('branch:id,name');
                return $warehouse;
            })->values(),
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
            'warehouse_ids' => ['nullable', 'array'],
            'warehouse_ids.*' => ['integer'],
            'default_warehouse_id' => ['nullable', 'integer'],
            'record_view' => ['sometimes', 'boolean'],
            'scope' => ['nullable', Rule::in(['branch', 'selected', 'all'])],
        ]);

        $scope = $validated['scope'] ?? ($employee->branch_id ? 'branch' : 'selected');
        if ($scope === 'all' && (int) $actor->role_id !== 1) {
            abort(403, 'Solo el propietario del tenant puede crear accesos con alcance global desde este flujo.');
        }

        $warehouseIds = $this->resolveWarehouseScope($employee, $scope, $validated['warehouse_ids'] ?? []);
        if ((int) $actor->is_all_warehouses !== 1 && $scope !== 'all') {
            $actorAllowed = app(WarehouseScopeService::class)->allowedWarehouseIds($actor);
            foreach ($warehouseIds as $warehouseId) {
                abort_unless(in_array((int) $warehouseId, $actorAllowed, true), 403, 'No puedes conceder acceso a una bodega fuera de tu propio alcance.');
            }
        }

        $defaultWarehouseId = ! empty($validated['default_warehouse_id']) ? (int) $validated['default_warehouse_id'] : null;
        if ($defaultWarehouseId && $scope !== 'all' && ! in_array($defaultWarehouseId, $warehouseIds, true)) {
            throw ValidationException::withMessages([
                'default_warehouse_id' => 'La bodega predeterminada debe estar dentro del alcance asignado al empleado.',
            ]);
        }

        $user = DB::transaction(function () use ($employee, $validated, $scope, $warehouseIds, $defaultWarehouseId) {
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
                'avatar' => 'no_avatar.png',
                'role_id' => (int) $validated['role_id'],
                'statut' => 1,
                'is_all_warehouses' => $scope === 'all' ? 1 : 0,
                'default_warehouse_id' => $defaultWarehouseId,
                'default_cash_drawer_id' => null,
                'record_view' => (bool) ($validated['record_view'] ?? false),
            ]);

            role_user::create([
                'user_id' => $user->id,
                'role_id' => (int) $validated['role_id'],
            ]);

            if ($scope !== 'all') {
                $user->assignedWarehouses()->sync($warehouseIds);
            }

            return $user;
        });

        return response()->json([
            'success' => true,
            'user' => $user->load('employee.branch:id,name'),
        ], 201);
    }

    public function link(Request $request, int $employeeId)
    {
        $this->authorizeAccess($request);
        $actor = $request->user('api');
        $employee = $this->employeeForActor($actor, $employeeId, true);

        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
        ]);

        $candidate = User::whereNull('deleted_at')->findOrFail($validated['user_id']);
        $this->assertCanManageUserScope($actor, $candidate);

        DB::transaction(function () use ($employee, $validated) {
            $lockedEmployee = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $user = User::whereNull('deleted_at')->lockForUpdate()->findOrFail($validated['user_id']);

            if ($user->employee_id && (int) $user->employee_id !== (int) $lockedEmployee->id) {
                throw ValidationException::withMessages(['user_id' => 'Este usuario ya está vinculado a otro empleado.']);
            }

            $existing = User::where('employee_id', $lockedEmployee->id)->where('id', '!=', $user->id)->exists();
            if ($existing) {
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

    private function resolveWarehouseScope(Employee $employee, string $scope, array $requested): array
    {
        if ($scope === 'all') return [];

        if ($scope === 'branch') {
            abort_unless($employee->branch_id, 422, 'El empleado no tiene una sucursal asignada.');
            return Warehouse::whereNull('deleted_at')
                ->where('branch_id', $employee->branch_id)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $requested = array_values(array_unique(array_map('intval', $requested)));
        if (! $requested) return [];

        $existing = Warehouse::whereNull('deleted_at')
            ->whereIn('id', $requested)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (count($existing) !== count($requested)) {
            throw ValidationException::withMessages(['warehouse_ids' => 'Una de las bodegas seleccionadas no existe.']);
        }

        if ($employee->branch_id) {
            $outsideBranch = Warehouse::whereIn('id', $requested)
                ->where(function ($q) use ($employee) {
                    $q->whereNull('branch_id')->orWhere('branch_id', '!=', $employee->branch_id);
                })
                ->exists();
            if ($outsideBranch) {
                throw ValidationException::withMessages([
                    'warehouse_ids' => 'Un empleado asignado a una sucursal no puede recibir acceso operativo a bodegas de otra sucursal desde este flujo.',
                ]);
            }
        }

        return $requested;
    }

    private function allowedBranchIds(User $actor): array
    {
        if ((int) $actor->is_all_warehouses === 1) {
            return Warehouse::whereNull('deleted_at')->whereNotNull('branch_id')->pluck('branch_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        }

        $warehouseIds = app(WarehouseScopeService::class)->allowedWarehouseIds($actor);
        $branchIds = Warehouse::whereNull('deleted_at')
            ->whereIn('id', $warehouseIds ?: [0])
            ->whereNotNull('branch_id')
            ->pluck('branch_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $employeeBranch = optional($actor->employee)->branch_id;
        if ($employeeBranch) $branchIds[] = (int) $employeeBranch;

        return array_values(array_unique($branchIds));
    }

    private function employeeForActor(User $actor, int $employeeId, bool $requireActive): Employee
    {
        $query = Employee::with('branch:id,name')->whereNull('deleted_at')->whereKey($employeeId);
        if ($requireActive) $query->whereNull('leaving_date');
        if ((int) $actor->is_all_warehouses !== 1) {
            $query->whereIn('branch_id', $this->allowedBranchIds($actor) ?: [0]);
        }
        return $query->firstOrFail();
    }

    private function assertCanManageUserScope(User $actor, User $candidate): void
    {
        if ((int) $actor->is_all_warehouses === 1) return;
        abort_if((int) $candidate->is_all_warehouses === 1, 403, 'No puedes administrar una cuenta con alcance global.');

        $allowed = app(WarehouseScopeService::class)->allowedWarehouseIds($actor);
        $candidateIds = $candidate->assignedWarehouses()->pluck('warehouses.id')->map(fn ($id) => (int) $id)->all();
        foreach ($candidateIds as $warehouseId) {
            abort_unless(in_array($warehouseId, $allowed, true), 403, 'No puedes administrar una cuenta fuera de tu alcance operativo.');
        }
    }

    private function authorizeAccess(Request $request): void
    {
        $user = $request->user('api');
        abort_unless($user, 401);
        abort_unless((int) $user->role_id === 1 || $user->hasPermissionName('users_edit'), 403);
    }
}
