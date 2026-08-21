<?php

namespace App\Http\Controllers\hrm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeAccount;
use App\Models\EmployeeExperience;
use App\Models\OfficeShift;
use App\Models\Warehouse;
use App\Services\WarehouseScopeService;
use App\utils\helpers;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeesController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'view', Employee::class);

        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';
        $helpers = new helpers;
        $param = [0 => 'like', 1 => 'like', 2 => '='];
        $columns = [0 => 'username', 1 => 'employment_type', 2 => 'company_id'];
        $data = [];

        $employees = Employee::with(
            'company:id,name',
            'branch:id,name',
            'office_shift:id,name',
            'department:id,department',
            'designation:id,designation'
        )->whereNull('deleted_at')->whereNull('leaving_date');

        $this->scopeEmployeesToUser($employees, $user);

        $filtered = $helpers->filter($employees, $columns, $param, $request)
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('firstname', 'LIKE', "%{$request->search}%")
                        ->orWhere('lastname', 'LIKE', "%{$request->search}%")
                        ->orWhere('username', 'LIKE', "%{$request->search}%");
                });
            });

        if ($request->filled('branch_id')) {
            $branchId = (int) $request->branch_id;
            $this->assertBranchAccess($user, $branchId);
            $filtered->where('branch_id', $branchId);
        }

        $totalRows = $filtered->count();
        if ($perPage == '-1') $perPage = $totalRows;

        $employees = $filtered->offset($offSet)->limit($perPage)->orderBy($order, $dir)->get();

        foreach ($employees as $employee) {
            $data[] = [
                'id' => $employee->id,
                'firstname' => $employee->firstname,
                'lastname' => $employee->lastname,
                'phone' => $employee->phone,
                'company_name' => optional($employee->company)->name,
                'branch_name' => optional($employee->branch)->name,
                'branch_id' => $employee->branch_id,
                'department_name' => optional($employee->department)->department,
                'designation_name' => optional($employee->designation)->designation,
                'office_shift_name' => optional($employee->office_shift)->name,
            ];
        }

        return response()->json([
            'employees' => $data,
            'companies' => Company::whereNull('deleted_at')->get(['id', 'name']),
            'branches' => $this->visibleBranches($user),
            'totalRows' => $totalRows,
        ]);
    }

    public function create(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'create', Employee::class);

        return response()->json([
            'companies' => Company::whereNull('deleted_at')->get(['id', 'name']),
            'branches' => $this->visibleBranches($user),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'create', Employee::class);

        $this->validate($request, [
            'firstname' => 'required|string',
            'lastname' => 'required|string',
            'gender' => 'required',
            'company_id' => 'required',
            'branch_id' => [
                'nullable', 'integer',
                Rule::exists('branches', 'id')->where(fn ($q) => $q->whereNull('deleted_at')->where('is_active', true)),
            ],
            'department_id' => 'required',
            'designation_id' => 'required',
            'office_shift_id' => 'required',
        ]);

        if ($request->filled('branch_id')) {
            $this->assertBranchAccess($user, (int) $request->branch_id);
        }

        $data = [
            'firstname' => $request['firstname'],
            'lastname' => $request['lastname'],
            'username' => $request['firstname'].' '.$request['lastname'],
            'country' => $request['country'],
            'email' => $request['email'],
            'gender' => $request['gender'],
            'phone' => $request['phone'],
            'birth_date' => $request['birth_date'],
            'company_id' => $request['company_id'],
            'branch_id' => $request->filled('branch_id') ? (int) $request['branch_id'] : null,
            'department_id' => $request['department_id'],
            'designation_id' => $request['designation_id'],
            'office_shift_id' => $request['office_shift_id'],
            'joining_date' => $request['joining_date'],
        ];

        $employee = Employee::create($data);

        return response()->json(['success' => true, 'employee_id' => $employee->id]);
    }

    public function show(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'view', Employee::class);

        $employee = $this->employeeForUser($user, (int) $id);
        $companies = Company::whereNull('deleted_at')->orderBy('id', 'desc')->get(['id', 'name']);
        $branches = $this->visibleBranches($user);
        $office_shifts = OfficeShift::where('company_id', $employee->company_id)->whereNull('deleted_at')->orderBy('id', 'desc')->get(['id', 'name']);
        $departments = Department::where('company_id', $employee->company_id)->whereNull('deleted_at')->orderBy('id', 'desc')->get(['id', 'department']);
        $designations = Designation::where('department_id', $employee->department_id)->whereNull('deleted_at')->where('is_active', true)->orderBy('id', 'desc')->get(['id', 'designation']);

        return response()->json(compact('employee', 'companies', 'branches', 'office_shifts', 'departments', 'designations'));
    }

    public function edit(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', Employee::class);

        $employee = $this->employeeForUser($user, (int) $id);
        $companies = Company::whereNull('deleted_at')->get(['id', 'name']);
        $branches = $this->visibleBranches($user);
        $office_shifts = OfficeShift::where('company_id', $employee->company_id)->whereNull('deleted_at')->get(['id', 'name']);
        $departments = Department::where('company_id', $employee->company_id)->whereNull('deleted_at')->get(['id', 'department']);
        $designations = Designation::where('department_id', $employee->department_id)->whereNull('deleted_at')->where('is_active', true)->get(['id', 'designation']);

        return response()->json(compact('employee', 'companies', 'branches', 'office_shifts', 'departments', 'designations'));
    }

    public function update(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', Employee::class);
        $employee = $this->employeeForUser($user, (int) $id);

        $this->validate($request, [
            'firstname' => 'required|string',
            'lastname' => 'required|string',
            'country' => 'required|string',
            'gender' => 'required',
            'phone' => 'required',
            'total_leave' => 'required|numeric|min:0',
            'company_id' => 'required',
            'branch_id' => [
                'nullable', 'integer',
                Rule::exists('branches', 'id')->where(fn ($q) => $q->whereNull('deleted_at')->where('is_active', true)),
            ],
            'department_id' => 'required',
            'designation_id' => 'required',
            'office_shift_id' => 'required',
            'basic_salary' => 'nullable|numeric',
            'hourly_rate' => 'nullable|numeric',
        ]);

        if ($request->filled('branch_id')) {
            $this->assertBranchAccess($user, (int) $request->branch_id);
        }

        $data = [];
        $data['firstname'] = $request['firstname'];
        $data['lastname'] = $request['lastname'];
        $data['username'] = $request['firstname'].' '.$request['lastname'];
        $data['country'] = $request['country'];
        $data['email'] = $request['email'];
        $data['gender'] = $request['gender'];
        $data['phone'] = $request['phone'];
        $data['birth_date'] = $request['birth_date'];
        $data['company_id'] = $request['company_id'];
        $data['branch_id'] = $request->filled('branch_id') ? (int) $request['branch_id'] : null;
        $data['department_id'] = $request['department_id'];
        $data['designation_id'] = $request['designation_id'];
        $data['office_shift_id'] = $request['office_shift_id'];
        $data['joining_date'] = $request['joining_date'];
        $data['role_users_id'] = $request['role_users_id'];
        $data['leaving_date'] = $request['leaving_date'] ? $request['leaving_date'] : null;
        $data['marital_status'] = $request['marital_status'];
        $data['employment_type'] = $request['employment_type'];
        $data['city'] = $request['city'];
        $data['province'] = $request['province'];
        $data['zipcode'] = $request['zipcode'];
        $data['address'] = $request['address'];
        $data['basic_salary'] = $request['basic_salary'];
        $data['hourly_rate'] = $request['hourly_rate'];

        if ($employee->total_leave == 0) {
            $data['total_leave'] = $request->total_leave;
            $data['remaining_leave'] = $request->total_leave;
        } elseif ($request->total_leave > $employee->total_leave) {
            $data['total_leave'] = $request->total_leave;
            $data['remaining_leave'] = $request->remaining_leave + ($request->total_leave - $employee->total_leave);
        } elseif ($request->total_leave < $employee->total_leave) {
            $data['total_leave'] = $request->total_leave;
            $data['remaining_leave'] = $request->remaining_leave - ($employee->total_leave - $request->total_leave);
        } else {
            $data['total_leave'] = $request->total_leave;
            $data['remaining_leave'] = $employee->remaining_leave;
        }

        $employee->update($data);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'delete', Employee::class);
        $employee = $this->employeeForUser($user, (int) $id);
        $employee->update(['deleted_at' => Carbon::now()]);
        return response()->json(['success' => true]);
    }

    public function delete_by_selection(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'delete', Employee::class);
        foreach ((array) $request->selectedIds as $employeeId) {
            $employee = $this->employeeForUser($user, (int) $employeeId);
            $employee->update(['deleted_at' => Carbon::now()]);
        }
        return response()->json(['success' => true]);
    }

    public function Get_employees_by_department(Request $request)
    {
        $user = $request->user('api');
        $employees = Employee::where('department_id', $request->id)->whereNull('deleted_at');
        $this->scopeEmployeesToUser($employees, $user);
        return response()->json($employees->orderBy('id', 'desc')->get(['id', 'username']));
    }

    public function Get_office_shift_by_company(Request $request)
    {
        $office_shifts = OfficeShift::where('company_id', $request->id)->whereNull('deleted_at')->orderBy('id', 'desc')->get(['id', 'name']);
        return response()->json($office_shifts);
    }

    public function update_social_profile(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', Employee::class);
        $employee = $this->employeeForUser($user, (int) $id);
        $employee->update([
            'skype' => $request['skype'],
            'facebook' => $request['facebook'],
            'whatsapp' => $request['whatsapp'],
            'twitter' => $request['twitter'],
            'linkedin' => $request['linkedin'],
        ]);
        return response()->json(['success' => true]);
    }

    public function get_experiences_by_employee(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'view', Employee::class);
        $employee = $this->employeeForUser($user, (int) $request->id);
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $experiences = EmployeeExperience::where('employee_id', $employee->id)->whereNull('deleted_at')->orderBy('id', 'desc');
        $totalRows = $experiences->count();
        if ($perPage == '-1') $perPage = $totalRows;
        $experiences = $experiences->offset($offSet)->limit($perPage)->orderBy('id', 'desc')->get();
        return response()->json(['totalRows' => $totalRows, 'experiences' => $experiences]);
    }

    public function get_accounts_by_employee(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'view', Employee::class);
        $employee = $this->employeeForUser($user, (int) $request->id);
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $accountsBank = EmployeeAccount::where('employee_id', $employee->id)->whereNull('deleted_at')->orderBy('id', 'desc');
        $totalRows = $accountsBank->count();
        if ($perPage == '-1') $perPage = $totalRows;
        $accountsBank = $accountsBank->offset($offSet)->limit($perPage)->orderBy('id', 'desc')->get();
        return response()->json(['totalRows' => $totalRows, 'accounts_bank' => $accountsBank]);
    }

    public function Get_employees_by_company(Request $request)
    {
        $user = $request->user('api');
        $employees = Employee::where('company_id', $request->id)->whereNull('deleted_at');
        $this->scopeEmployeesToUser($employees, $user);
        return response()->json($employees->orderBy('id', 'desc')->get(['id', 'username']));
    }

    private function visibleBranches($user)
    {
        $query = Branch::whereNull('deleted_at')->where('is_active', true)->orderBy('name');
        if (! $user || (int) $user->is_all_warehouses === 1) {
            return $query->get(['id', 'code', 'name']);
        }

        return $query->whereIn('id', $this->allowedBranchIds($user))->get(['id', 'code', 'name']);
    }

    private function allowedBranchIds($user): array
    {
        if (! $user) return [];
        if ((int) $user->is_all_warehouses === 1) {
            return Branch::whereNull('deleted_at')->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $warehouseIds = app(WarehouseScopeService::class)->allowedWarehouseIds($user);
        $branchIds = Warehouse::whereNull('deleted_at')
            ->whereIn('id', $warehouseIds ?: [0])
            ->whereNotNull('branch_id')
            ->pluck('branch_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $employeeBranchId = optional($user->employee)->branch_id;
        if ($employeeBranchId) $branchIds[] = (int) $employeeBranchId;

        return array_values(array_unique($branchIds));
    }

    private function assertBranchAccess($user, int $branchId): void
    {
        if ((int) $user->is_all_warehouses === 1) return;
        abort_unless(in_array($branchId, $this->allowedBranchIds($user), true), 403, 'No tienes acceso a esta sucursal.');
    }

    private function scopeEmployeesToUser($query, $user): void
    {
        if (! $user || (int) $user->is_all_warehouses === 1) return;
        $query->whereIn('branch_id', $this->allowedBranchIds($user) ?: [0]);
    }

    private function employeeForUser($user, int $employeeId): Employee
    {
        $query = Employee::whereNull('deleted_at')->whereKey($employeeId);
        $this->scopeEmployeesToUser($query, $user);
        return $query->firstOrFail();
    }
}
