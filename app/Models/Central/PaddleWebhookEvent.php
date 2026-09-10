<?php

declare(strict_types=1);

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

class PaddleWebhookEvent extends Model
{
    protected $connection = 'central';

    protected $table = 'paddle_webhook_events';

    protected $fillable = [
        'event_id',
        'event_type',
        'occurred_at',
        'payload_hash',
        'status',
        'processed_at',
        'error',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
