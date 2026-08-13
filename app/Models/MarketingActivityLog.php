<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingActivityLog extends Model
{
    protected $table = 'marketing_activity_logs';

    protected $fillable = [
        'user_id', 'module', 'reference_id', 'action', 'description',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
