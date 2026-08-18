<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SarPointOfIssue extends Model
{
    protected $fillable = [
        'establishment_code', 'point_code', 'name', 'address',
        'warehouse_id', 'cash_drawer_id', 'active',
    ];

    protected $casts = ['active' => 'boolean'];

    public function authorizations()
    {
        return $this->hasMany(SarAuthorization::class, 'point_of_issue_id');
    }
}
