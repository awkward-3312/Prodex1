<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeAccessController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeAccess($request);

        $employees = Employee::with(['branch:id,name', 'designation:id,designation', 'user:id,employee_id,firstname,lastname,email,role_id,statut'])
            ->whereNull('deleted_at')
            ->whereNull('leaving_date')
            ->orderBy('firstname')
            ->orderBy('lastname')
            ->get(['id', 'branch_id', 'designation_id', 'firstname', 'lastname', 'email']);

        $unlinkedUsers = User::whereNull('deleted_at')
            ->whereNull('employee_id')
            ->orderBy('firstname')
            ->orderBy('lastname')
            ->get(['id', 'firstname', 'lastname', 'email', 'role_id', 'statut']);

        return response()->json([
            'employees' => $employees,
            'unlinked_users' => $unlinkedUsers,
        ]);
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

    private function authorizeAccess(Request $request): void
    {
        $user = $request->user('api');
        abort_unless($user, 401);
        abort_unless((int) $user->role_id === 1 || $user->hasPermissionName('users_edit'), 403);
    }
}
