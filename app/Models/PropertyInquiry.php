<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PropertyInquiry extends Model
{
    use SoftDeletes;

    protected $table = 'property_inquiries';

    protected $fillable = [
        'property_id', 'name', 'phone', 'email', 'message', 'status',
    ];

    public function property()
    {
        return $this->belongsTo(Property::class, 'property_id');
    }
}
