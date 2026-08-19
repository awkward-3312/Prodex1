<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\CentralUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function index(): View
    {
        $admins = CentralUser::orderBy('id')->get();
        $currentId = Auth::guard('central')->id();

        return view('central.super.admins.index', compact('admins', 'currentId'));
    }

    public function create(): View
    {
        $permissions = CentralUser::PERMISSIONS;

        return view('central.super.admins.create', compact('permissions'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'email'       => ['required', 'email', 'max:255', Rule::unique('central.central_users', 'email')],
            'password'    => ['required', 'string', 'min:8', 'confirmed'],
            'role'        => ['required', 'in:super_admin,admin'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(array_keys(CentralUser::PERMISSIONS))],
        ]);

        CentralUser::create([
            'name'        => $validated['name'],
            'email'       => $validated['email'],
            'password'    => Hash::make($validated['password']),
            'role'        => $validated['role'],
            'permissions' => $validated['role'] === 'admin' ? ($validated['permissions'] ?? []) : null,
        ]);

        return redirect()
            ->route('super.admins.index')
            ->with('success', "Administrador \"{$validated['name']}\" creado correctamente.");
    }

    public function edit(CentralUser $admin): View
    {
        $permissions = CentralUser::PERMISSIONS;

        return view('central.super.admins.edit', compact('admin', 'permissions'));
    }

    public function update(Request $request, CentralUser $admin): RedirectResponse
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'email'       => ['required', 'email', 'max:255', Rule::unique('central.central_users', 'email')->ignore($admin->id)],
            'password'    => ['nullable', 'string', 'min:8', 'confirmed'],
            'role'        => ['required', 'in:super_admin,admin'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(array_keys(CentralUser::PERMISSIONS))],
        ]);

        $data = [
            'name'        => $validated['name'],
            'email'       => $validated['email'],
            'role'        => $validated['role'],
            'permissions' => $validated['role'] === 'admin' ? ($validated['permissions'] ?? []) : null,
        ];

        if (! empty($validated['password'])) {
            $data['password'] = Hash::make($validated['password']);
        }

        $admin->update($data);

        return redirect()
            ->route('super.admins.index')
            ->with('success', "Administrador \"{$admin->name}\" actualizado correctamente.");
    }

    public function destroy(CentralUser $admin): RedirectResponse
    {
        $currentId = Auth::guard('central')->id();

        if ($admin->id === $currentId) {
            return back()->withErrors(['delete' => 'No puedes eliminar tu propia cuenta.']);
        }

        if (CentralUser::count() <= 1) {
            return back()->withErrors(['delete' => 'No se puede eliminar el último administrador restante.']);
        }

        $name = $admin->name;
        $admin->delete();

        return redirect()
            ->route('super.admins.index')
            ->with('success', "Administrador \"{$name}\" eliminado correctamente.");
    }
}