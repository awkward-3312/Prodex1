<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\utils\helpers;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PermissionsController extends BaseController
{
    // ----------- GET ALL Roles --------------\\

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Role::class);
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';
        $helpers = new helpers;

        $roles = Role::where('deleted_at', '=', null)
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('name', 'LIKE', "%{$request->search}%")
                        ->orWhere('description', 'LIKE', "%{$request->search}%");
                });
            });

        $totalRows = $roles->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }

        $roles = $roles->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        return response()->json([
            'roles' => $roles,
            'totalRows' => $totalRows,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Role::class);

        try {
            $request->validate([
                'role.name' => 'required|string|max:120',
                'role.description' => 'nullable|string|max:500',
                'permissions' => 'nullable|array',
                'permissions.*' => 'string|max:120',
            ]);

            \DB::transaction(function () use ($request) {
                $role = new Role;
                $role->name = $request->input('role.name');
                $role->label = $request->input('role.name');
                $role->status = 0;
                $role->description = $request->input('role.description');
                $role->save();

                $this->syncPermissions($role, $request->input('permissions', []));
            }, 10);

            return response()->json(['success' => true]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 422,
                'msg' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function show($id)
    {
        //
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Role::class);

        try {
            $request->validate([
                'role.name' => 'required|string|max:120',
                'role.description' => 'nullable|string|max:500',
                'permissions' => 'nullable|array',
                'permissions.*' => 'string|max:120',
            ]);

            \DB::transaction(function () use ($request, $id) {
                $role = Role::findOrFail($id);
                $role->name = $request->input('role.name');
                $role->label = $request->input('role.name');
                $role->description = $request->input('role.description');
                $role->save();

                $this->syncPermissions($role, $request->input('permissions', []));
            }, 10);

            return response()->json(['success' => true]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 422,
                'msg' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Role::class);

        Role::whereId($id)->update([
            'deleted_at' => Carbon::now(),
        ]);

        return response()->json(['success' => true]);
    }

    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Role::class);

        $selectedIds = $request->selectedIds;
        foreach ($selectedIds as $role_id) {
            Role::whereId($role_id)->update([
                'deleted_at' => Carbon::now(),
            ]);
        }

        return response()->json(['success' => true]);
    }

    public function getRoleswithoutpaginate()
    {
        $roles = Role::where('deleted_at', null)->get(['id', 'name']);

        return response()->json($roles);
    }

    public function edit(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Role::class);

        if ($id != '1') {
            $role = Role::with('permissions')->where('deleted_at', '=', null)->findOrFail($id);
            $item = [
                'name' => $role->name,
                'description' => $role->description,
            ];

            $data = $role->permissions->pluck('name')->values()->all();

            return response()->json([
                'permissions' => $data,
                'role' => $item,
            ]);
        }

        return response()->json([
            'success' => false,
        ], 401);
    }

    private function syncPermissions(Role $role, array $permissions): void
    {
        $permissionNames = collect($permissions)
            ->map(fn ($permission) => trim((string) $permission))
            ->filter()
            ->unique()
            ->values();

        $permissionIds = $permissionNames->map(function (string $permissionName) {
            return Permission::firstOrCreate(['name' => $permissionName])->id;
        })->all();

        $role->permissions()->sync($permissionIds);
    }
}
