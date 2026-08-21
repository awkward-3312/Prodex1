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
use App\utils\helpers;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeesController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Employee::class);
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

        $Filtred = $helpers->filter($employees, $columns, $param, $request)
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('firstname', 'LIKE', "%{$request->search}%")
                        ->orWhere('lastname', 'LIKE', "%{$request->search}%")
                        ->orWhere('username', 'LIKE', "%{$request->search}%");
                });
            });

        if ($request->filled('branch_id')) {
            $employees->where('branch_id', (int) $request->branch_id);
        }

        $totalRows = $employees->count();
        if ($perPage == '-1') $perPage = $totalRows;

        $employees = $employees->offset($offSet)->limit($perPage)->orderBy($order, $dir)->get();

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
            'branches' => Branch::whereNull('deleted_at')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'totalRows' => $totalRows,
        ]);
    }

    public function create(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Employee::class);

        return response()->json([
            'companies' => Company::whereNull('deleted_at')->get(['id', 'name']),
            'branches' => Branch::whereNull('deleted_at')->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Employee::class);

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
        $this->authorizeForUser($request->user('api'), 'view', Employee::class);

        $employee = Employee::whereNull('deleted_at')->findOrFail($id);
        $companies = Company::whereNull('deleted_at')->orderBy('id', 'desc')->get(['id', 'name']);
        $branches = Branch::whereNull('deleted_at')->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']);
        $office_shifts = OfficeShift::where('company_id', $employee->company_id)->whereNull('deleted_at')->orderBy('id', 'desc')->get(['id', 'name']);
        $departments = Department::where('company_id', $employee->company_id)->whereNull('deleted_at')->orderBy('id', 'desc')->get(['id', 'department']);
        $designations = Designation::where('department_id', $employee->department_id)->whereNull('deleted_at')->where('is_active', true)->orderBy('id', 'desc')->get(['id', 'designation']);

        return response()->json(compact('employee', 'companies', 'branches', 'office_shifts', 'departments', 'designations'));
    }

    public function edit(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Employee::class);

        $employee = Employee::whereNull('deleted_at')->findOrFail($id);
        $companies = Company::whereNull('deleted_at')->get(['id', 'name']);
        $branches = Branch::whereNull('deleted_at')->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']);
        $office_shifts = OfficeShift::where('company_id', $employee->company_id)->whereNull('deleted_at')->get(['id', 'name']);
        $departments = Department::where('company_id', $employee->company_id)->whereNull('deleted_at')->get(['id', 'department']);
        $designations = Designation::where('department_id', $employee->department_id)->whereNull('deleted_at')->where('is_active', true)->get(['id', 'designation']);

        return response()->json(compact('employee', 'companies', 'branches', 'office_shifts', 'departments', 'designations'));
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Employee::class);

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

        $employee_leave_info = Employee::find($id);
        if ($employee_leave_info->total_leave == 0) {
            $data['total_leave'] = $request->total_leave;
            $data['remaining_leave'] = $request->total_leave;
        } elseif ($request->total_leave > $employee_leave_info->total_leave) {
            $data['total_leave'] = $request->total_leave;
            $data['remaining_leave'] = $request->remaining_leave + ($request->total_leave - $employee_leave_info->total_leave);
        } elseif ($request->total_leave < $employee_leave_info->total_leave) {
            $data['total_leave'] = $request->total_leave;
            $data['remaining_leave'] = $request->remaining_leave - ($employee_leave_info->total_leave - $request->total_leave);
        } else {
            $data['total_leave'] = $request->total_leave;
            $data['remaining_leave'] = $employee_leave_info->remaining_leave;
        }

        Employee::find($id)->update($data);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Employee::class);
        Employee::whereId($id)->update(['deleted_at' => Carbon::now()]);
        return response()->json(['success' => true]);
    }

    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Employee::class);
        foreach ((array) $request->selectedIds as $employee_id) {
            Employee::whereId($employee_id)->update(['deleted_at' => Carbon::now()]);
        }
        return response()->json(['success' => true]);
    }

    public function Get_employees_by_department(Request $request)
    {
        $employees = Employee::where('department_id', $request->id)->whereNull('deleted_at')->orderBy('id', 'desc')->get(['id', 'username']);
        return response()->json($employees);
    }

    public function Get_office_shift_by_company(Request $request)
    {
        $office_shifts = OfficeShift::where('company_id', $request->id)->whereNull('deleted_at')->orderBy('id', 'desc')->get(['id', 'name']);
        return response()->json($office_shifts);
    }

    public function update_social_profile(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Employee::class);
        Employee::whereId($id)->update([
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
        $this->authorizeForUser($request->user('api'), 'view', Employee::class);
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $experiences = EmployeeExperience::where('employee_id', $request->id)->whereNull('deleted_at')->orderBy('id', 'desc');
        $totalRows = $experiences->count();
        if ($perPage == '-1') $perPage = $totalRows;
        $experiences = $experiences->offset($offSet)->limit($perPage)->orderBy('id', 'desc')->get();
        return response()->json(['totalRows' => $totalRows, 'experiences' => $experiences]);
    }

    public function get_accounts_by_employee(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Employee::class);
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $accounts_bank = EmployeeAccount::where('employee_id', $request->id)->whereNull('deleted_at')->orderBy('id', 'desc');
        $totalRows = $accounts_bank->count();
        if ($perPage == '-1') $perPage = $totalRows;
        $accounts_bank = $accounts_bank->offset($offSet)->limit($perPage)->orderBy('id', 'desc')->get();
        return response()->json(['totalRows' => $totalRows, 'accounts_bank' => $accounts_bank]);
    }

    public function Get_employees_by_company(Request $request)
    {
        $employees = Employee::where('company_id', $request->id)->whereNull('deleted_at')->orderBy('id', 'desc')->get(['id', 'username']);
        return response()->json($employees);
    }
}
