<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportTicketAttachment extends Model
{
    protected $connection = 'central';

    protected $table = 'support_ticket_attachments';

    protected $fillable = [
        'support_ticket_id',
        'support_ticket_message_id',
        'file_path',
        'original_name',
        'mime_type',
        'size',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    protected $appends = ['url'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportTicketMessage::class, 'support_ticket_message_id');
    }

    /** Publicly accessible URL for the stored file. */
    public function getUrlAttribute(): string
    {
        // global_asset() — not asset() — so tenancy doesn't rewrite this into
        // the /tenancy/assets/ route; the file lives in public/images/tenants/.
        return global_asset($this->file_path);
    }

    public function isImage(): bool
    {
        return is_string($this->mime_type) && str_starts_with($this->mime_type, 'image/');
    }
}
