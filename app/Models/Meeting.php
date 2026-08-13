<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Meeting extends Model
{
    use SoftDeletes;

    protected $table = 'meetings';

    protected $fillable = [
        'title', 'description', 'agenda', 'meeting_date', 'start_time', 'end_time',
        'location', 'type', 'status', 'platform', 'meeting_link', 'reminder_minutes',
        'reminder_sent', 'organizer_id', 'created_by',
    ];

    protected $casts = [
        'meeting_date'  => 'date:Y-m-d',
        'reminder_sent' => 'boolean',
    ];

    public function participants()
    {
        return $this->hasMany(MeetingParticipant::class, 'meeting_id');
    }

    public function notes()
    {
        return $this->hasMany(MeetingNote::class, 'meeting_id');
    }

    public function attachments()
    {
        return $this->hasMany(MeetingAttachment::class, 'meeting_id');
    }

    public function logs()
    {
        return $this->hasMany(MeetingActivityLog::class, 'meeting_id');
    }

    public function organizer()
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }
}
