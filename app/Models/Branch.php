<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'code', 'name', 'type', 'phone', 'email', 'country', 'city', 'address',
        'manager_employee_id', 'default_warehouse_id', 'is_active',
    ];

    protected $casts = [
        'manager_employee_id' => 'integer',
        'default_warehouse_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function warehouses()
    {
        return $this->hasMany(Warehouse::class, 'branch_id');
    }

    public function employees()
    {
        return $this->hasMany(Employee::class, 'branch_id');
    }

    public function manager()
    {
        return $this->belongsTo(Employee::class, 'manager_employee_id');
    }

    public function defaultWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }
}
