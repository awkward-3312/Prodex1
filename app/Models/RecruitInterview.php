<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RecruitInterview extends Model
{
    use SoftDeletes;

    protected $table = 'recruit_interviews';

    protected $fillable = [
        'application_id', 'type', 'scheduled_at', 'duration_minutes', 'location',
        'meeting_link', 'interviewer_id', 'status', 'rating', 'feedback', 'notes',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
    ];

    public function application()
    {
        return $this->belongsTo(RecruitApplication::class, 'application_id');
    }
}
