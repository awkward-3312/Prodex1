<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Services\PermissionCatalogService;
use App\utils\helpers;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PermissionsController extends BaseController
{
    private const OWNER_ROLE_ID = 1;

    public function index(Request $request)
    {
        $actor = $request->user('api');
        $this->authorizeForUser($actor, 'view', Role::class);
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';
        $helpers = new helpers;

        $roles = Role::whereNull('deleted_at')
            ->when((int) $actor->role_id !== self::OWNER_ROLE_ID, fn ($query) => $query->where('id', '!=', self::OWNER_ROLE_ID))
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('name', 'LIKE', "%{$request->search}%")
                        ->orWhere('description', 'LIKE', "%{$request->search}%");
                });
            });

        $totalRows = $roles->count();
        if ($perPage == '-1') $perPage = $totalRows;

        return response()->json([
            'roles' => $roles->offset($offSet)->limit($perPage)->orderBy($order, $dir)->get(),
            'totalRows' => $totalRows,
        ]);
    }

    public function store(Request $request, PermissionCatalogService $catalog)
    {
        $this->authorizeForUser($request->user('api'), 'create', Role::class);

        try {
            $request->validate([
                'role.name' => 'required|string|max:120',
                'role.description' => 'nullable|string|max:500',
                'permissions' => 'nullable|array',
                'permissions.*' => 'string|max:120',
            ]);

            \DB::transaction(function () use ($request, $catalog) {
                $role = Role::create([
                    'name' => $request->input('role.name'),
                    'label' => $request->input('role.name'),
                    'status' => 0,
                    'description' => $request->input('role.description'),
                ]);
                $this->syncPermissions($role, $request->input('permissions', []), $catalog);
            }, 10);

            return response()->json(['success' => true]);
        } catch (ValidationException $e) {
            return response()->json(['status' => 422, 'msg' => 'error', 'errors' => $e->errors()], 422);
        }
    }

    public function show($id) {}

    public function update(Request $request, $id, PermissionCatalogService $catalog)
    {
        $this->authorizeForUser($request->user('api'), 'update', Role::class);
        $this->assertMutableRole((int) $id);

        try {
            $request->validate([
                'role.name' => 'required|string|max:120',
                'role.description' => 'nullable|string|max:500',
                'permissions' => 'nullable|array',
                'permissions.*' => 'string|max:120',
            ]);

            \DB::transaction(function () use ($request, $id, $catalog) {
                $role = Role::whereNull('deleted_at')->findOrFail($id);
                $role->update([
                    'name' => $request->input('role.name'),
                    'label' => $request->input('role.name'),
                    'description' => $request->input('role.description'),
                ]);
                $this->syncPermissions($role, $request->input('permissions', []), $catalog);
            }, 10);

            return response()->json(['success' => true]);
        } catch (ValidationException $e) {
            return response()->json(['status' => 422, 'msg' => 'error', 'errors' => $e->errors()], 422);
        }
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Role::class);
        $this->assertMutableRole((int) $id);
        Role::whereId($id)->update(['deleted_at' => Carbon::now()]);
        return response()->json(['success' => true]);
    }

    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Role::class);
        $selectedIds = collect($request->selectedIds ?? [])->map(fn ($id) => (int) $id)->unique()->values();
        abort_if($selectedIds->contains(self::OWNER_ROLE_ID), 403, 'El rol propietario es un rol de sistema y no puede eliminarse.');
        Role::whereIn('id', $selectedIds)->update(['deleted_at' => Carbon::now()]);
        return response()->json(['success' => true]);
    }

    public function getRoleswithoutpaginate(Request $request)
    {
        $actor = $request->user('api');
        $roles = Role::whereNull('deleted_at')
            ->when($actor && (int) $actor->role_id !== self::OWNER_ROLE_ID, fn ($query) => $query->where('id', '!=', self::OWNER_ROLE_ID))
            ->get(['id', 'name']);
        return response()->json($roles);
    }

    public function edit(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Role::class);
        $this->assertMutableRole((int) $id);

        $role = Role::with('permissions')->whereNull('deleted_at')->findOrFail($id);
        return response()->json([
            'permissions' => $role->permissions->pluck('name')->values()->all(),
            'role' => ['name' => $role->name, 'description' => $role->description],
        ]);
    }

    private function syncPermissions(Role $role, array $permissions, PermissionCatalogService $catalog): void
    {
        $permissionNames = $catalog->normalizeSelection($permissions);
        $permissionIds = Permission::whereIn('name', $permissionNames)->pluck('id')->all();
        $role->permissions()->sync($permissionIds);
    }

    private function assertMutableRole(int $roleId): void
    {
        abort_if($roleId === self::OWNER_ROLE_ID, 403, 'El rol propietario es un rol de sistema protegido y no puede modificarse ni eliminarse.');
    }
}
