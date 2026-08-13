<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MarketingTemplate extends Model
{
    use SoftDeletes;

    protected $table = 'marketing_templates';

    protected $fillable = [
        'name', 'type', 'category', 'subject', 'content', 'created_by',
    ];

    public function campaigns()
    {
        return $this->hasMany(MarketingCampaign::class, 'template_id');
    }
}
