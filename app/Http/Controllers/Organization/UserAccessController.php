<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashDrawer;
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
use Intervention\Image\ImageManagerStatic as Image;

class UserAccessController extends Controller
{
    public function options(Request $request)
    {
        $this->authorizeCreate($request);
        $actor = $request->user('api');
        $branchIds = app(BranchScopeService::class)->allowedBranchIds($actor);

        $branches = Branch::whereNull('deleted_at')->where('is_active', true)->orderBy('name');
        if ((int) $actor->role_id !== 1) $branches->whereIn('id', $branchIds ?: [0]);
        $branches = $branches->get(['id', 'code', 'name', 'default_inventory_location_id']);

        $visibleBranchIds = $branches->pluck('id')->map(fn ($id) => (int) $id)->all();
        $locations = InventoryLocation::active()
            ->whereIn('branch_id', $visibleBranchIds ?: [0])
            ->orderBy('branch_id')->orderBy('name')
            ->get(['id', 'branch_id', 'code', 'name', 'type', 'is_sellable', 'is_default_sales']);

        $cashDrawers = CashDrawer::whereNull('deleted_at')
            ->where('is_active', true)
            ->whereIn('branch_id', $visibleBranchIds ?: [0])
            ->whereNotNull('inventory_location_id')
            ->orderBy('branch_id')->orderBy('name')
            ->get(['id', 'branch_id', 'inventory_location_id', 'name', 'code', 'is_active']);

        $roles = Role::with('permissions:id,name')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'description'])
            ->map(function (Role $role) {
                $permissions = $role->permissions->pluck('name');
                $usesPos = $permissions->contains('Pos_view');
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'description' => $role->description,
                    'uses_pos' => $usesPos,
                    'requires_cash_drawer' => $usesPos && ! $permissions->contains('cash_register_override_assignment'),
                ];
            })->values();

        return response()->json([
            'roles' => $roles,
            'branches' => $branches,
            'inventory_locations' => $locations,
            'cash_drawers' => $cashDrawers,
            'can_global_scope' => (int) $actor->role_id === 1,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeCreate($request);
        $actor = $request->user('api');

        $validated = $request->validate([
            'firstname' => ['required', 'string', 'min:2', 'max:30'],
            'lastname' => ['required', 'string', 'min:2', 'max:30'],
            'username' => ['required', 'string', 'min:3', 'max:60'],
            'email' => ['required', 'email', 'max:192', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:80'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where(fn ($q) => $q->whereNull('deleted_at'))],
            'scope' => ['required', Rule::in(['selected', 'all'])],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer'],
            'inventory_location_ids' => ['nullable', 'array'],
            'inventory_location_ids.*' => ['integer'],
            'default_branch_id' => ['nullable', 'integer'],
            'default_inventory_location_id' => ['nullable', 'integer'],
            'default_cash_drawer_id' => ['nullable', 'integer'],
            'record_view' => ['sometimes', 'boolean'],
            'avatar' => ['nullable', 'image', 'max:2048'],
        ]);

        $scope = $validated['scope'];
        if ($scope === 'all' && (int) $actor->role_id !== 1) abort(403, 'Solo el propietario puede conceder alcance global.');

        $branchIds = $scope === 'all' ? [] : $this->validatedBranches($validated['branch_ids'] ?? []);
        if ($scope !== 'all' && ! $branchIds) {
            throw ValidationException::withMessages(['branch_ids' => 'Selecciona al menos una sucursal.']);
        }
        $this->assertActorCanGrantBranches($actor, $branchIds, $scope);

        $locationIds = $scope === 'all' ? [] : $this->validatedLocations($branchIds, $validated['inventory_location_ids'] ?? []);
        $defaultBranchId = $scope === 'all' ? null : ($validated['default_branch_id'] ?? ($branchIds[0] ?? null));
        if ($defaultBranchId && ! in_array((int) $defaultBranchId, $branchIds, true)) {
            throw ValidationException::withMessages(['default_branch_id' => 'La sucursal predeterminada debe estar dentro del alcance asignado.']);
        }

        $defaultLocationId = $scope === 'all' ? null : ($validated['default_inventory_location_id'] ?? $this->defaultLocationForBranch($defaultBranchId));
        if ($defaultLocationId) {
            $location = InventoryLocation::active()->find((int) $defaultLocationId);
            if (! $location || (int) $location->branch_id !== (int) $defaultBranchId) {
                throw ValidationException::withMessages(['default_inventory_location_id' => 'La ubicación predeterminada debe pertenecer a la sucursal predeterminada.']);
            }
            if (! in_array((int) $defaultLocationId, $locationIds, true)) $locationIds[] = (int) $defaultLocationId;
        }

        $requiresDrawer = $this->roleRequiresCashDrawer((int) $validated['role_id']);
        $defaultCashDrawerId = ! empty($validated['default_cash_drawer_id']) ? (int) $validated['default_cash_drawer_id'] : null;

        if ($requiresDrawer && $scope === 'all') {
            throw ValidationException::withMessages([
                'scope' => 'Un rol POS restringido debe tener una sucursal, ubicación y caja física predeterminadas; no puede crearse con alcance global.',
            ]);
        }
        if ($requiresDrawer && ! $defaultCashDrawerId) {
            throw ValidationException::withMessages([
                'default_cash_drawer_id' => 'Este rol opera POS sin permiso para cambiar de caja. Asigna una caja física predeterminada.',
            ]);
        }
        if ($defaultCashDrawerId) {
            $this->assertDrawerMatchesContext($defaultCashDrawerId, $defaultBranchId, $defaultLocationId);
        }

        $filename = $this->storeAvatar($request);

        $user = DB::transaction(function () use ($validated, $scope, $branchIds, $locationIds, $defaultBranchId, $defaultLocationId, $defaultCashDrawerId, $filename) {
            $user = User::create([
                'firstname' => $validated['firstname'],
                'lastname' => $validated['lastname'],
                'username' => $validated['username'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make($validated['password']),
                'avatar' => $filename,
                'role_id' => (int) $validated['role_id'],
                'statut' => 1,
                'is_all_warehouses' => $scope === 'all' ? 1 : 0,
                'default_warehouse_id' => null,
                'default_branch_id' => $defaultBranchId ? (int) $defaultBranchId : null,
                'default_inventory_location_id' => $defaultLocationId ? (int) $defaultLocationId : null,
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
            'user' => $user->load(['defaultBranch:id,name', 'defaultInventoryLocation:id,name', 'defaultCashDrawer:id,name,code']),
        ], 201);
    }

    private function validatedBranches(array $requested): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $requested), fn ($id) => $id > 0)));
        if (! $ids) return [];

        $existing = Branch::whereNull('deleted_at')->where('is_active', true)->whereIn('id', $ids)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($existing) !== count($ids)) {
            throw ValidationException::withMessages(['branch_ids' => 'Una de las sucursales seleccionadas no existe o está inactiva.']);
        }
        return $ids;
    }

    private function validatedLocations(array $branchIds, array $requested): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $requested), fn ($id) => $id > 0)));
        if (! $ids) {
            return Branch::whereIn('id', $branchIds)
                ->whereNotNull('default_inventory_location_id')
                ->pluck('default_inventory_location_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        }

        $existing = InventoryLocation::active()->whereIn('id', $ids)->whereIn('branch_id', $branchIds)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($existing) !== count($ids)) {
            throw ValidationException::withMessages(['inventory_location_ids' => 'Una ubicación seleccionada no pertenece a las sucursales asignadas.']);
        }
        return $ids;
    }

    private function roleRequiresCashDrawer(int $roleId): bool
    {
        $role = Role::with('permissions:id,name')->whereNull('deleted_at')->find($roleId);
        if (! $role) return false;
        $permissions = $role->permissions->pluck('name');
        return $permissions->contains('Pos_view') && ! $permissions->contains('cash_register_override_assignment');
    }

    private function assertDrawerMatchesContext(int $drawerId, ?int $branchId, ?int $locationId): void
    {
        if (! $branchId || ! $locationId) {
            throw ValidationException::withMessages([
                'default_cash_drawer_id' => 'Selecciona primero la sucursal y ubicación predeterminadas.',
            ]);
        }

        $drawer = CashDrawer::whereNull('deleted_at')->where('is_active', true)->find($drawerId);
        if (! $drawer || (int) $drawer->branch_id !== (int) $branchId || (int) $drawer->inventory_location_id !== (int) $locationId) {
            throw ValidationException::withMessages([
                'default_cash_drawer_id' => 'La caja física debe estar activa y pertenecer a la sucursal y ubicación predeterminadas.',
            ]);
        }
    }

    private function assertActorCanGrantBranches(User $actor, array $branchIds, string $scope): void
    {
        if ((int) $actor->role_id === 1 || $scope === 'all') return;
        $allowed = app(BranchScopeService::class)->allowedBranchIds($actor);
        foreach ($branchIds as $branchId) {
            abort_unless(in_array($branchId, $allowed, true), 403, 'No puedes conceder una sucursal fuera de tu propio alcance.');
        }
    }

    private function defaultLocationForBranch(?int $branchId): ?int
    {
        if (! $branchId) return null;
        $id = Branch::whereNull('deleted_at')->where('is_active', true)->whereKey($branchId)->value('default_inventory_location_id');
        return $id ? (int) $id : null;
    }

    private function storeAvatar(Request $request): string
    {
        if (! $request->hasFile('avatar')) return 'no_avatar.png';

        $image = $request->file('avatar');
        $filename = rand(11111111, 99999999).$image->getClientOriginalName();
        $imageResize = Image::make($image->getRealPath());
        $imageResize->resize(128, 128);
        $imageResize->save(public_path('/images/avatar/'.$filename));
        return $filename;
    }

    private function authorizeCreate(Request $request): void
    {
        $actor = $request->user('api');
        abort_unless($actor, 401);
        abort_unless((int) $actor->role_id === 1 || $actor->hasPermissionName('users_add'), 403);
    }
}
