<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'branch_id', 'name', 'mobile', 'country', 'city', 'email', 'zip',
    ];

    protected $casts = [
        'branch_id' => 'integer',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function assignedUsers()
    {
        return $this->belongsToMany('App\Models\User');
    }

    public function locations()
    {
        return $this->hasMany(WarehouseLocation::class);
    }
}
