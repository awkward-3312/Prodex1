<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AttendanceDevice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'provider', 'model', 'serial_number', 'connection_mode',
        'external_identifier', 'timezone', 'is_active', 'last_seen_at', 'settings',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'is_active' => 'boolean',
        'last_seen_at' => 'datetime',
        'settings' => 'array',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function identifiers()
    {
        return $this->hasMany(AttendanceEmployeeIdentifier::class);
    }

    public function punches()
    {
        return $this->hasMany(AttendancePunch::class);
    }
}
