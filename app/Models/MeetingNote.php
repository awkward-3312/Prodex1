<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MeetingNote extends Model
{
    use SoftDeletes;

    protected $table = 'meeting_notes';

    protected $fillable = [
        'meeting_id', 'type', 'content', 'assigned_to', 'due_date', 'status', 'created_by',
    ];

    protected $casts = [
        'due_date' => 'date:Y-m-d',
    ];

    public function meeting()
    {
        return $this->belongsTo(Meeting::class, 'meeting_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
