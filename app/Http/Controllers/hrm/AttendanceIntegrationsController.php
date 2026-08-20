<?php

namespace App\Http\Controllers\hrm;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AttendanceDevice;
use App\Models\AttendanceEmployeeIdentifier;
use App\Models\AttendancePunch;
use App\Models\Company;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class AttendanceIntegrationsController extends Controller
{
    public function devices(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Attendance::class);

        $query = AttendanceDevice::with('company:id,name')
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->orderBy('name');

        return response()->json(['devices' => $query->get()]);
    }

    public function storeDevice(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Attendance::class);

        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'name' => ['required', 'string', 'max:191'],
            'provider' => ['required', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:191'],
            'serial_number' => ['nullable', 'string', 'max:191'],
            'connection_mode' => ['required', Rule::in(['import', 'push', 'network', 'api'])],
            'external_identifier' => ['nullable', 'string', 'max:191'],
            'timezone' => ['nullable', 'timezone'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $device = AttendanceDevice::create($data);

        return response()->json(['device' => $device->load('company:id,name')], 201);
    }

    public function updateDevice(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Attendance::class);

        $device = AttendanceDevice::findOrFail($id);
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'name' => ['required', 'string', 'max:191'],
            'provider' => ['required', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:191'],
            'serial_number' => ['nullable', 'string', 'max:191'],
            'connection_mode' => ['required', Rule::in(['import', 'push', 'network', 'api'])],
            'external_identifier' => ['nullable', 'string', 'max:191'],
            'timezone' => ['nullable', 'timezone'],
            'is_active' => ['required', 'boolean'],
        ]);

        $device->update($data);

        return response()->json(['device' => $device->fresh()->load('company:id,name')]);
    }

    public function destroyDevice(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Attendance::class);

        AttendanceDevice::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    public function employeeIdentifiers(Request $request, $employeeId)
    {
        $this->authorizeForUser($request->user('api'), 'view', Employee::class);

        $employee = Employee::findOrFail($employeeId);
        $identifiers = AttendanceEmployeeIdentifier::with('device:id,name,provider,model')
            ->where('employee_id', $employee->id)
            ->orderByDesc('is_active')
            ->orderBy('provider')
            ->get();

        $devices = AttendanceDevice::where('company_id', $employee->company_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'provider', 'model']);

        return response()->json([
            'employee_id' => $employee->id,
            'company_id' => $employee->company_id,
            'identifiers' => $identifiers,
            'devices' => $devices,
        ]);
    }

    public function storeEmployeeIdentifier(Request $request, $employeeId)
    {
        $this->authorizeForUser($request->user('api'), 'update', Employee::class);

        $employee = Employee::findOrFail($employeeId);
        $data = $request->validate([
            'attendance_device_id' => ['nullable', 'integer', 'exists:attendance_devices,id'],
            'provider' => ['required', 'string', 'max:80'],
            'external_user_id' => ['required', 'string', 'max:191'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (! empty($data['attendance_device_id'])) {
            $device = AttendanceDevice::findOrFail($data['attendance_device_id']);
            abort_unless((int) $device->company_id === (int) $employee->company_id, 422, 'El dispositivo no pertenece a la empresa del empleado.');
            $data['provider'] = $device->provider;
        }

        $externalUserId = trim($data['external_user_id']);
        $duplicate = AttendanceEmployeeIdentifier::where('company_id', $employee->company_id)
            ->where('provider', $data['provider'])
            ->where('external_user_id', $externalUserId)
            ->when(
                ! empty($data['attendance_device_id']),
                fn ($q) => $q->where('attendance_device_id', $data['attendance_device_id']),
                fn ($q) => $q->whereNull('attendance_device_id')
            )
            ->where('employee_id', '<>', $employee->id)
            ->exists();

        abort_if($duplicate, 422, 'Ese código de marcaje ya está vinculado a otro empleado.');

        $identifier = AttendanceEmployeeIdentifier::updateOrCreate(
            [
                'employee_id' => $employee->id,
                'attendance_device_id' => $data['attendance_device_id'] ?? null,
                'provider' => $data['provider'],
            ],
            [
                'company_id' => $employee->company_id,
                'external_user_id' => $externalUserId,
                'is_active' => $data['is_active'] ?? true,
            ]
        );

        $this->linkPendingPunches($identifier);

        return response()->json(['identifier' => $identifier->load('device:id,name,provider,model')], 201);
    }

    public function destroyEmployeeIdentifier(Request $request, $employeeId, $identifierId)
    {
        $this->authorizeForUser($request->user('api'), 'update', Employee::class);

        $identifier = AttendanceEmployeeIdentifier::where('employee_id', $employeeId)->findOrFail($identifierId);
        $identifier->delete();

        return response()->json(['success' => true]);
    }

    public function punches(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Attendance::class);

        $perPage = min(max((int) $request->input('limit', 50), 1), 200);
        $query = AttendancePunch::with(['employee:id,username', 'device:id,name'])
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('processing_status'), fn ($q) => $q->where('processing_status', $request->input('processing_status')))
            ->orderByDesc('occurred_at');

        return response()->json($query->paginate($perPage));
    }

    public function importPunches(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Attendance::class);

        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'device_id' => ['nullable', 'integer', 'exists:attendance_devices,id'],
            'provider' => ['nullable', 'string', 'max:80'],
            'file' => ['required', 'file', 'mimes:csv,txt,xls,xlsx', 'max:10240'],
        ]);

        $company = Company::findOrFail($data['company_id']);
        $device = null;
        if (! empty($data['device_id'])) {
            $device = AttendanceDevice::findOrFail($data['device_id']);
            abort_unless((int) $device->company_id === (int) $company->id, 422, 'El dispositivo no pertenece a la empresa seleccionada.');
        }

        $provider = $device?->provider ?: ($data['provider'] ?? 'generic');
        $timezone = $device?->timezone ?: config('app.timezone');

        $sheet = IOFactory::load($request->file('file')->getRealPath())->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);
        abort_if(count($rows) < 2, 422, 'El archivo no contiene marcajes para importar.');

        $headers = array_map(fn ($header) => $this->normalizeHeader((string) $header), array_shift($rows));
        $columns = $this->detectColumns($headers);

        abort_unless($columns['external_user_id'] !== null, 422, 'No se encontró una columna de código/ID de empleado.');
        abort_unless($columns['datetime'] !== null || ($columns['date'] !== null && $columns['time'] !== null), 422, 'No se encontraron columnas de fecha y hora.');

        $result = ['imported' => 0, 'duplicates' => 0, 'matched' => 0, 'unmatched' => 0, 'errors' => 0];
        $sourceReference = 'import:'.now()->format('YmdHis').':'.$request->file('file')->getClientOriginalName();

        foreach ($rows as $row) {
            try {
                $externalUserId = trim((string) ($row[$columns['external_user_id']] ?? ''));
                if ($externalUserId === '') {
                    continue;
                }

                $occurredAt = $this->parseOccurrence($row, $columns, $timezone);
                if (! $occurredAt) {
                    $result['errors']++;
                    continue;
                }

                $identifier = $this->resolveIdentifier($company->id, $provider, $externalUserId, $device?->id);
                $fingerprint = hash('sha256', implode('|', [
                    $company->id,
                    $device?->id ?: 'none',
                    $provider,
                    $externalUserId,
                    $occurredAt->format('Y-m-d H:i:s'),
                ]));

                if (AttendancePunch::where('source_fingerprint', $fingerprint)->exists()) {
                    $result['duplicates']++;
                    continue;
                }

                AttendancePunch::create([
                    'company_id' => $company->id,
                    'employee_id' => $identifier?->employee_id,
                    'attendance_employee_identifier_id' => $identifier?->id,
                    'attendance_device_id' => $device?->id,
                    'provider' => $provider,
                    'external_user_id' => $externalUserId,
                    'occurred_at' => $occurredAt,
                    'punch_type' => $columns['punch_type'] !== null ? ($row[$columns['punch_type']] ?? null) : null,
                    'verification_method' => $columns['verification_method'] !== null ? ($row[$columns['verification_method']] ?? null) : null,
                    'source' => 'import',
                    'source_reference' => $sourceReference,
                    'source_fingerprint' => $fingerprint,
                    'processing_status' => $identifier ? 'pending' : 'unmatched',
                    'raw_payload' => $this->rowAsPayload($headers, $row),
                ]);

                $result['imported']++;
                $identifier ? $result['matched']++ : $result['unmatched']++;
            } catch (\Throwable $e) {
                report($e);
                $result['errors']++;
            }
        }

        return response()->json([
            'success' => true,
            'summary' => $result,
            'message' => 'Los marcajes se guardaron como eventos originales. Aún no modifican la asistencia calculada.',
        ]);
    }

    private function resolveIdentifier(int $companyId, string $provider, string $externalUserId, ?int $deviceId): ?AttendanceEmployeeIdentifier
    {
        $base = AttendanceEmployeeIdentifier::where('company_id', $companyId)
            ->where('provider', $provider)
            ->where('external_user_id', $externalUserId)
            ->where('is_active', true);

        if ($deviceId) {
            $exact = (clone $base)->where('attendance_device_id', $deviceId)->first();
            if ($exact) {
                return $exact;
            }
        }

        return $base->whereNull('attendance_device_id')->first();
    }

    private function linkPendingPunches(AttendanceEmployeeIdentifier $identifier): void
    {
        $query = AttendancePunch::where('company_id', $identifier->company_id)
            ->where('provider', $identifier->provider)
            ->where('external_user_id', $identifier->external_user_id)
            ->whereNull('employee_id');

        // A device-specific identifier only claims punches from that exact device.
        // A general provider identifier intentionally matches every device of the
        // provider inside the same company.
        if ($identifier->attendance_device_id) {
            $query->where('attendance_device_id', $identifier->attendance_device_id);
        }

        $query->update([
            'employee_id' => $identifier->employee_id,
            'attendance_employee_identifier_id' => $identifier->id,
            'processing_status' => 'pending',
            'processing_message' => null,
        ]);
    }

    private function normalizeHeader(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        $value = mb_strtolower(trim($value));
        $value = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'u', 'n'], $value);

        return preg_replace('/[^a-z0-9]+/', '_', $value) ?: '';
    }

    private function detectColumns(array $headers): array
    {
        $synonyms = [
            'external_user_id' => ['user_id', 'userid', 'employee_id', 'employee_code', 'codigo_empleado', 'codigo', 'pin', 'uid', 'enroll_number', 'enrollnumber', 'person_id', 'ac_no', 'acno'],
            'datetime' => ['datetime', 'date_time', 'timestamp', 'fecha_hora', 'fecha_y_hora', 'check_datetime', 'punch_datetime'],
            'date' => ['date', 'fecha', 'punch_date', 'check_date'],
            'time' => ['time', 'hora', 'punch_time', 'check_time'],
            'punch_type' => ['punch_type', 'type', 'tipo', 'status', 'in_out', 'state'],
            'verification_method' => ['verification_method', 'verify_type', 'method', 'metodo', 'verification'],
        ];

        $detected = [];
        foreach ($synonyms as $key => $options) {
            $detected[$key] = null;
            foreach ($options as $option) {
                $index = array_search($option, $headers, true);
                if ($index !== false) {
                    $detected[$key] = $index;
                    break;
                }
            }
        }

        return $detected;
    }

    private function parseOccurrence(array $row, array $columns, string $timezone): ?Carbon
    {
        if ($columns['datetime'] !== null) {
            return $this->parseDateTimeValue($row[$columns['datetime']] ?? null, $timezone);
        }

        $dateValue = $row[$columns['date']] ?? null;
        $timeValue = $row[$columns['time']] ?? null;
        $date = $this->parseDateValue($dateValue);
        $time = $this->parseTimeValue($timeValue);

        if (! $date || ! $time) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d H:i:s', $date.' '.$time, $timezone);
    }

    private function parseDateTimeValue($value, string $timezone): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value) && (float) $value > 1000) {
            $dateTime = ExcelDate::excelToDateTimeObject((float) $value, new \DateTimeZone($timezone));

            return Carbon::instance($dateTime);
        }

        $formats = ['Y-m-d H:i:s', 'Y-m-d H:i', 'd/m/Y H:i:s', 'd/m/Y H:i', 'm/d/Y H:i:s', 'm/d/Y H:i'];
        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, trim((string) $value), $timezone);
            } catch (\Throwable $e) {
            }
        }

        try {
            return Carbon::parse((string) $value, $timezone);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function parseDateValue($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value) && (float) $value > 1000) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        foreach (['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y'] as $format) {
            try {
                return Carbon::createFromFormat($format, trim((string) $value))->format('Y-m-d');
            } catch (\Throwable $e) {
            }
        }

        return null;
    }

    private function parseTimeValue($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('H:i:s');
        }

        foreach (['H:i:s', 'H:i', 'h:i:s A', 'h:i A'] as $format) {
            try {
                return Carbon::createFromFormat($format, trim((string) $value))->format('H:i:s');
            } catch (\Throwable $e) {
            }
        }

        return null;
    }

    private function rowAsPayload(array $headers, array $row): array
    {
        $payload = [];
        foreach ($headers as $index => $header) {
            $payload[$header !== '' ? $header : 'column_'.$index] = $row[$index] ?? null;
        }

        return $payload;
    }
}
