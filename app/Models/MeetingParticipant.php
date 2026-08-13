<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeetingParticipant extends Model
{
    protected $table = 'meeting_participants';

    protected $fillable = [
        'meeting_id', 'user_id', 'invitation_status', 'attendance_status',
        'is_notified', 'notified_at',
    ];

    protected $casts = [
        'is_notified' => 'boolean',
        'notified_at' => 'datetime',
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
