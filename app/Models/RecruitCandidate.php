<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RecruitCandidate extends Model
{
    use SoftDeletes;

    protected $table = 'recruit_candidates';

    protected $fillable = [
        'first_name', 'last_name', 'email', 'phone', 'date_of_birth', 'gender',
        'address', 'city', 'state', 'country', 'zip_code', 'current_company',
        'current_position', 'current_salary', 'expected_salary', 'experience_years',
        'skills', 'education', 'resume_path', 'photo', 'linkedin_url',
        'portfolio_url', 'notes', 'source',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'current_salary' => 'decimal:2',
        'expected_salary' => 'decimal:2',
    ];

    public function applications()
    {
        return $this->hasMany(RecruitApplication::class, 'candidate_id');
    }

    public function getFullNameAttribute()
    {
        return $this->first_name . ' ' . $this->last_name;
    }
}
