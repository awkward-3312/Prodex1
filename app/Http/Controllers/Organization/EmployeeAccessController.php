<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Role;
use App\Models\role_user;
use App\Models\User;
use App\Models\Warehouse;
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

        $employees = Employee::with([
                'branch:id,name',
                'designation:id,designation,suggested_role_key',
                'user:id,employee_id,firstname,lastname,email,role_id,statut,default_warehouse_id,is_all_warehouses',
            ])
            ->whereNull('deleted_at')
            ->whereNull('leaving_date')
            ->orderBy('firstname')
            ->orderBy('lastname')
            ->get(['id', 'branch_id', 'designation_id', 'firstname', 'lastname', 'email', 'phone']);

        $unlinkedUsers = User::whereNull('deleted_at')
            ->whereNull('employee_id')
            ->orderBy('firstname')
            ->orderBy('lastname')
            ->get(['id', 'firstname', 'lastname', 'email', 'role_id', 'statut']);

        return response()->json([
            'employees' => $employees,
            'unlinked_users' => $unlinkedUsers,
            'roles' => Role::whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'description']),
            'warehouses' => Warehouse::with('branch:id,name')
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->get(['id', 'branch_id', 'name']),
        ]);
    }

    public function create(Request $request, int $employeeId)
    {
        $this->authorizeAccess($request);

        $employee = Employee::with('branch:id,name')->whereNull('deleted_at')->findOrFail($employeeId);
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

        $actor = $request->user('api');
        $scope = $validated['scope'] ?? ($employee->branch_id ? 'branch' : 'selected');
        if ($scope === 'all' && (int) $actor->role_id !== 1) {
            abort(403, 'Solo el propietario del tenant puede crear accesos con alcance global desde este flujo.');
        }

        $warehouseIds = $this->resolveWarehouseScope($employee, $scope, $validated['warehouse_ids'] ?? []);
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

        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
        ]);

        DB::transaction(function () use ($employeeId, $validated) {
            $employee = Employee::whereNull('deleted_at')->lockForUpdate()->findOrFail($employeeId);
            $user = User::whereNull('deleted_at')->lockForUpdate()->findOrFail($validated['user_id']);

            if ($user->employee_id && (int) $user->employee_id !== (int) $employee->id) {
                throw ValidationException::withMessages(['user_id' => 'Este usuario ya está vinculado a otro empleado.']);
            }

            $existing = User::where('employee_id', $employee->id)->where('id', '!=', $user->id)->exists();
            if ($existing) {
                throw ValidationException::withMessages(['employee_id' => 'Este empleado ya tiene una cuenta de acceso vinculada.']);
            }

            $user->update(['employee_id' => $employee->id]);
        });

        return response()->json(['success' => true]);
    }

    public function unlink(Request $request, int $employeeId)
    {
        $this->authorizeAccess($request);

        User::where('employee_id', $employeeId)->update(['employee_id' => null]);

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

    private function authorizeAccess(Request $request): void
    {
        $user = $request->user('api');
        abort_unless($user, 401);
        abort_unless((int) $user->role_id === 1 || $user->hasPermissionName('users_edit'), 403);
    }
}
