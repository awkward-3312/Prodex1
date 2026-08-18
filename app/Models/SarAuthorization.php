<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SarAuthorization extends Model
{
    protected $fillable = [
        'point_of_issue_id', 'document_type', 'cai', 'range_start',
        'range_end', 'next_number', 'authorization_date', 'deadline', 'status',
    ];

    protected $casts = [
        'authorization_date' => 'date',
        'deadline' => 'date',
        'range_start' => 'integer',
        'range_end' => 'integer',
        'next_number' => 'integer',
    ];

    public function pointOfIssue()
    {
        return $this->belongsTo(SarPointOfIssue::class, 'point_of_issue_id');
    }

    public function documents()
    {
        return $this->hasMany(SarFiscalDocument::class, 'authorization_id');
    }
}
