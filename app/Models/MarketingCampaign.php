<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MarketingCampaign extends Model
{
    use SoftDeletes;

    protected $table = 'marketing_campaigns';

    protected $fillable = [
        'title', 'description', 'type', 'subject', 'message_content', 'attachment',
        'template_id', 'segment_id', 'all_customers', 'status', 'send_immediately',
        'scheduled_at', 'sent_at', 'total_recipients', 'sent_count', 'delivered_count',
        'failed_count', 'pending_count', 'created_by',
    ];

    protected $casts = [
        'all_customers'    => 'boolean',
        'send_immediately' => 'boolean',
        'scheduled_at'     => 'datetime',
        'sent_at'          => 'datetime',
    ];

    public function segment()
    {
        return $this->belongsTo(MarketingSegment::class, 'segment_id');
    }

    public function template()
    {
        return $this->belongsTo(MarketingTemplate::class, 'template_id');
    }

    public function recipients()
    {
        return $this->hasMany(MarketingCampaignRecipient::class, 'campaign_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
