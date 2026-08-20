<?php

namespace App\Http\Controllers\hrm;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AttendancesController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Attendance::class);

        $user = Auth::user();
        $viewRecords = $user->hasRecordView();
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField ?: 'id';
        $dir = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';

        $query = Attendance::with('employee', 'company')
            ->where('deleted_at', null)
            ->when(! $viewRecords, fn ($q) => $q->where('user_id', Auth::id()))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->search;
                $q->where(function ($sub) use ($search) {
                    $sub->where('date', 'LIKE', "%{$search}%")
                        ->orWhereHas('employee', fn ($employee) => $employee->where('username', 'LIKE', "%{$search}%"))
                        ->orWhereHas('company', fn ($company) => $company->where('name', 'LIKE', "%{$search}%"));
                });
            });

        $totalRows = $query->count();
        if ($perPage == '-1') {
            $perPage = max($totalRows, 1);
            $offSet = 0;
        }

        $rows = $query->offset($offSet)->limit($perPage)->orderBy($order, $dir)->get();
        $data = $rows->map(function ($attendance) {
            return [
                'id' => $attendance->id,
                'date' => $attendance->date,
                'clock_in' => $attendance->clock_in,
                'clock_out' => $attendance->clock_out,
                'total_work' => $attendance->total_work,
                'source' => $attendance->source ?: 'manual',
                'company_id' => $attendance->company_id,
                'employee_id' => $attendance->employee_id,
                'company_name' => optional($attendance->company)->name,
                'employee_username' => optional($attendance->employee)->username,
            ];
        })->values();

        return response()->json(['attendances' => $data, 'totalRows' => $totalRows]);
    }

    public function create(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Attendance::class);

        return response()->json([
            'companies' => Company::where('deleted_at', null)->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Attendance::class);
        $validated = $this->validateAttendanceRequest($request);
        $employee = $this->employeeForCompany($validated['employee_id'], $validated['company_id']);

        $data = $this->buildAttendanceData($employee, $validated);
        $data['user_id'] = auth()->id();
        $data['source'] = 'manual';
        $data['clock_in_ip'] = '';
        $data['clock_out_ip'] = '';

        Attendance::create($data);

        return response()->json(['success' => true]);
    }

    public function show($id)
    {
        // Legacy resource route compatibility.
    }

    public function edit(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Attendance::class);

        return response()->json([
            'companies' => Company::where('deleted_at', null)->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Attendance::class);
        $attendance = Attendance::findOrFail($id);
        $validated = $this->validateAttendanceRequest($request);
        $employee = $this->employeeForCompany($validated['employee_id'], $validated['company_id']);

        $attendance->update($this->buildAttendanceData($employee, $validated));

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Attendance::class);
        Attendance::whereId($id)->update(['deleted_at' => Carbon::now()]);

        return response()->json(['success' => true]);
    }

    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Attendance::class);

        foreach ((array) $request->selectedIds as $attendanceId) {
            Attendance::whereId($attendanceId)->update(['deleted_at' => Carbon::now()]);
        }

        return response()->json(['success' => true]);
    }

    private function validateAttendanceRequest(Request $request): array
    {
        return $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'date' => ['required', 'date'],
            'clock_in' => ['required'],
            'clock_out' => ['required'],
        ]);
    }

    private function employeeForCompany(int $employeeId, int $companyId): Employee
    {
        $employee = Employee::with('office_shift')->where('deleted_at', null)->findOrFail($employeeId);
        abort_unless((int) $employee->company_id === (int) $companyId, 422, 'El empleado no pertenece a la compañía seleccionada.');

        return $employee;
    }

    private function buildAttendanceData(Employee $employee, array $validated): array
    {
        $date = Carbon::parse($validated['date'])->format('Y-m-d');
        $clockIn = $this->dateTimeForAttendance($date, $validated['clock_in']);
        $clockOut = $this->dateTimeForAttendance($date, $validated['clock_out']);
        if ($clockOut->lessThanOrEqualTo($clockIn)) {
            $clockOut->addDay();
        }

        $day = strtolower(Carbon::parse($date)->format('l'));
        $shift = $employee->office_shift;
        $shiftInValue = $shift ? $shift->{$day.'_in'} : null;
        $shiftOutValue = $shift ? $shift->{$day.'_out'} : null;

        $data = [
            'employee_id' => $employee->id,
            'company_id' => $validated['company_id'],
            'date' => $date,
            'status' => 'present',
            'clock_in_out' => 0,
            'total_work' => $this->duration($clockIn, $clockOut),
            'late_time' => '00:00',
            'depart_early' => '00:00',
            'overtime' => '00:00',
        ];

        // A company may record attendance without enforcing a fixed schedule.
        // Empty hours on the assigned shift mean: keep the employee's real times
        // and do not classify lateness, early departure or overtime.
        if (! $shiftInValue || ! $shiftOutValue) {
            $data['clock_in'] = $clockIn->format('H:i');
            $data['clock_out'] = $clockOut->format('H:i');

            return $data;
        }

        $shiftIn = $this->dateTimeForAttendance($date, $shiftInValue);
        $shiftOut = $this->dateTimeForAttendance($date, $shiftOutValue);
        if ($shiftOut->lessThanOrEqualTo($shiftIn)) {
            $shiftOut->addDay();
        }

        if ($clockIn->greaterThan($shiftIn)) {
            $data['clock_in'] = $clockIn->format('H:i');
            $data['late_time'] = $this->duration($shiftIn, $clockIn);
        } else {
            // Preserve the legacy calculation rule: an early arrival counts from
            // the scheduled start for attendance totals, while the raw punch can
            // still be retained separately by the integration layer.
            $data['clock_in'] = $shiftIn->format('H:i');
        }

        if ($clockOut->lessThan($shiftOut)) {
            $data['clock_out'] = $clockOut->format('H:i');
            $data['depart_early'] = $this->duration($clockOut, $shiftOut);
        } elseif ($clockOut->greaterThan($shiftOut)) {
            $data['clock_out'] = $clockOut->format('H:i');
            $data['overtime'] = $this->duration($shiftOut, $clockOut);
        } else {
            $data['clock_out'] = $shiftOut->format('H:i');
        }

        return $data;
    }

    private function dateTimeForAttendance(string $date, $time): Carbon
    {
        $value = trim((string) $time);
        foreach (['H:i:s', 'H:i', 'h:i A', 'h:i:s A'] as $format) {
            try {
                return Carbon::createFromFormat('Y-m-d '.$format, $date.' '.$value);
            } catch (\Throwable $e) {
            }
        }

        return Carbon::parse($date.' '.$value);
    }

    private function duration(Carbon $from, Carbon $to): string
    {
        $minutes = (int) round($from->diffInMinutes($to));

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
