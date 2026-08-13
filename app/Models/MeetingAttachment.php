<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeetingAttachment extends Model
{
    protected $table = 'meeting_attachments';

    protected $fillable = [
        'meeting_id', 'file_name', 'file_path', 'file_type', 'file_size', 'uploaded_by',
    ];

    public function meeting()
    {
        return $this->belongsTo(Meeting::class, 'meeting_id');
    }
}
