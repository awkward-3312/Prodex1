<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappLog extends Model
{
    protected $table = 'whatsapp_logs';

    protected $fillable = [
        'recipient',
        'template_key',
        'message_type',
        'body',
        'status',
        'provider_message_id',
        'error',
        'meta',
        'sent_at',
    ];

    protected $casts = [
        'meta'    => 'array',
        'sent_at' => 'datetime',
    ];

    public const STATUS_PENDING   = 'pending';
    public const STATUS_SENT      = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_READ      = 'read';
    public const STATUS_FAILED    = 'failed';
}
