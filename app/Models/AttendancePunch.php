<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendancePunch extends Model
{
    protected $fillable = [
        'company_id', 'employee_id', 'attendance_employee_identifier_id', 'attendance_device_id',
        'provider', 'external_user_id', 'occurred_at', 'punch_type', 'verification_method',
        'source', 'source_reference', 'source_fingerprint', 'processing_status', 'processed_at',
        'processing_message', 'raw_payload',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'employee_id' => 'integer',
        'attendance_employee_identifier_id' => 'integer',
        'attendance_device_id' => 'integer',
        'occurred_at' => 'datetime',
        'processed_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function identifier()
    {
        return $this->belongsTo(AttendanceEmployeeIdentifier::class, 'attendance_employee_identifier_id');
    }

    public function device()
    {
        return $this->belongsTo(AttendanceDevice::class, 'attendance_device_id');
    }
}
