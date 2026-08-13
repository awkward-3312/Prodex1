<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class TenantUserController extends Controller
{
    /**
     * GET /super/tenants/{tenant}/users
     * List all users for a given tenant.
     */
    public function index(Request $request, Tenant $tenant): View
    {
        $tenant->load('domains');

        $users = [];
        $error = null;

        try {
            $tenant->run(function () use (&$users, $request) {
                $query = User::with('roles');

                if ($search = $request->input('search')) {
                    $query->where(function ($q) use ($search) {
                        $q->where('firstname', 'like', "%{$search}%")
                          ->orWhere('lastname', 'like', "%{$search}%")
                          ->orWhere('email', 'like', "%{$search}%")
                          ->orWhere('username', 'like', "%{$search}%");
                    });
                }

                if ($request->has('status') && $request->input('status') !== '') {
                    $query->where('statut', (int) $request->input('status'));
                }

                $users = $query->orderBy('id')->get();
            });
        } catch (\Throwable $e) {
            $error = 'Could not connect to tenant database. The workspace may not be provisioned yet.';
        }

        return view('central.super.tenants.users', compact('tenant', 'users', 'error'));
    }

    /**
     * POST /super/tenants/{tenant}/users/{userId}/block
     */
    public function block(Tenant $tenant, int $userId): RedirectResponse
    {
        $userName = '';

        $tenant->run(function () use ($userId, &$userName) {
            $user = User::findOrFail($userId);
            $user->update(['statut' => 0]);
            $userName = trim($user->firstname . ' ' . $user->lastname);
        });

        return back()->with('success', "User \"{$userName}\" has been blocked.");
    }

    /**
     * POST /super/tenants/{tenant}/users/{userId}/unblock
     */
    public function unblock(Tenant $tenant, int $userId): RedirectResponse
    {
        $userName = '';

        $tenant->run(function () use ($userId, &$userName) {
            $user = User::findOrFail($userId);
            $user->update(['statut' => 1]);
            $userName = trim($user->firstname . ' ' . $user->lastname);
        });

        return back()->with('success', "User \"{$userName}\" has been unblocked.");
    }

    /**
     * POST /super/tenants/{tenant}/users/{userId}/password
     */
    public function changePassword(Request $request, Tenant $tenant, int $userId): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $userName = '';
        $hashed = Hash::make($validated['password']);

        $tenant->run(function () use ($userId, $hashed, &$userName) {
            $user = User::findOrFail($userId);
            $user->update(['password' => $hashed]);
            $userName = trim($user->firstname . ' ' . $user->lastname);
        });

        return back()->with('success', __('central.PasswordChangedFor', ['name' => $userName]));
    }
}
