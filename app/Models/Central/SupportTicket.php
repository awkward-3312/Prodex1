<?php

namespace App\Models\Central;

use App\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    protected $connection = 'central';

    protected $table = 'support_tickets';

    // ── Status ──────────────────────────────────────────────────────────
    public const STATUS_OPEN     = 'open';
    public const STATUS_PENDING  = 'pending';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED   = 'closed';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_PENDING,
        self::STATUS_RESOLVED,
        self::STATUS_CLOSED,
    ];

    // ── Category ────────────────────────────────────────────────────────
    public const CATEGORY_BUG             = 'bug';
    public const CATEGORY_BILLING         = 'billing';
    public const CATEGORY_TECHNICAL       = 'technical';
    public const CATEGORY_FEATURE_REQUEST = 'feature_request';
    public const CATEGORY_ACCOUNT         = 'account';

    public const CATEGORIES = [
        self::CATEGORY_BUG,
        self::CATEGORY_TECHNICAL,
        self::CATEGORY_BILLING,
        self::CATEGORY_FEATURE_REQUEST,
        self::CATEGORY_ACCOUNT,
    ];

    // ── Priority ────────────────────────────────────────────────────────
    public const PRIORITY_LOW    = 'low';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_HIGH   = 'high';
    public const PRIORITY_URGENT = 'urgent';

    public const PRIORITIES = [
        self::PRIORITY_LOW,
        self::PRIORITY_MEDIUM,
        self::PRIORITY_HIGH,
        self::PRIORITY_URGENT,
    ];

    protected $fillable = [
        'ticket_number',
        'tenant_id',
        'opened_by_user_id',
        'opened_by_name',
        'opened_by_email',
        'subject',
        'category',
        'priority',
        'status',
        'assigned_to',
        'last_reply_at',
        'last_reply_by',
        'tenant_unread',
        'admin_unread',
        'resolved_at',
        'closed_at',
    ];

    protected $casts = [
        'last_reply_at' => 'datetime',
        'resolved_at'   => 'datetime',
        'closed_at'     => 'datetime',
        'tenant_unread' => 'boolean',
        'admin_unread'  => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $ticket) {
            if (empty($ticket->ticket_number)) {
                $ticket->ticket_number = static::generateTicketNumber();
            }
        });
    }

    public static function generateTicketNumber(): string
    {
        $next = (int) static::max('id') + 1;

        return 'TKT-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    // ── Relations ───────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(CentralUser::class, 'assigned_to');
    }

    /** All messages, including internal notes (for the super-admin view). */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->orderBy('created_at');
    }

    /** Messages visible to the tenant (excludes internal notes). */
    public function publicMessages(): HasMany
    {
        return $this->messages()->where('is_internal_note', false);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SupportTicketAttachment::class);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_PENDING], true);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CLOSED], true);
    }

    /** Display name for the tenant who owns this ticket. */
    public function tenantName(): string
    {
        return $this->tenant->company_name
            ?? $this->tenant->name
            ?? $this->tenant_id;
    }
}
