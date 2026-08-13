<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RecruitApplication extends Model
{
    use SoftDeletes;

    protected $table = 'recruit_applications';

    protected $fillable = [
        'job_id', 'candidate_id', 'stage', 'applied_date', 'cover_letter',
        'rating', 'notes', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'applied_date' => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function job()
    {
        return $this->belongsTo(RecruitJob::class, 'job_id');
    }

    public function candidate()
    {
        return $this->belongsTo(RecruitCandidate::class, 'candidate_id');
    }

    public function interviews()
    {
        return $this->hasMany(RecruitInterview::class, 'application_id');
    }
}
