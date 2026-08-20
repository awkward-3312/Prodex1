<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AttendanceEmployeeIdentifier extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'employee_id', 'attendance_device_id', 'provider',
        'external_user_id', 'is_active', 'metadata',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'employee_id' => 'integer',
        'attendance_device_id' => 'integer',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function device()
    {
        return $this->belongsTo(AttendanceDevice::class, 'attendance_device_id');
    }

    public function punches()
    {
        return $this->hasMany(AttendancePunch::class);
    }
}
