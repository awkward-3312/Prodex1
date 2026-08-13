<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicketMessage extends Model
{
    protected $connection = 'central';

    protected $table = 'support_ticket_messages';

    public const AUTHOR_TENANT = 'tenant';
    public const AUTHOR_ADMIN  = 'admin';

    protected $fillable = [
        'support_ticket_id',
        'author_type',
        'author_id',
        'author_name',
        'is_internal_note',
        'body',
    ];

    protected $casts = [
        'is_internal_note' => 'boolean',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SupportTicketAttachment::class, 'support_ticket_message_id');
    }

    public function fromTenant(): bool
    {
        return $this->author_type === self::AUTHOR_TENANT;
    }

    public function fromAdmin(): bool
    {
        return $this->author_type === self::AUTHOR_ADMIN;
    }
}
