<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RecruitJob extends Model
{
    use SoftDeletes;

    protected $table = 'recruit_jobs';

    protected $fillable = [
        'title', 'slug', 'category_id', 'department_id', 'job_type', 'location',
        'description', 'requirements', 'benefits', 'salary_min', 'salary_max',
        'currency', 'vacancies', 'status', 'experience_level', 'deadline', 'created_by',
    ];

    protected $casts = [
        'salary_min' => 'decimal:2',
        'salary_max' => 'decimal:2',
        'deadline' => 'date',
    ];

    public function category()
    {
        return $this->belongsTo(RecruitJobCategory::class, 'category_id');
    }

    public function applications()
    {
        return $this->hasMany(RecruitApplication::class, 'job_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }
}
