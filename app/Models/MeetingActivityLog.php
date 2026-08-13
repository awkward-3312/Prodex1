<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeetingActivityLog extends Model
{
    protected $table = 'meeting_activity_logs';

    protected $fillable = [
        'meeting_id', 'user_id', 'action', 'description',
    ];

    public function meeting()
    {
        return $this->belongsTo(Meeting::class, 'meeting_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
