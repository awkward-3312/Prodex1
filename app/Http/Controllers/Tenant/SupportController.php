<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Central\SupportTicket;
use App\Models\Central\SupportTicketAttachment;
use App\Models\Central\SupportTicketMessage;
use App\Services\SupportTicketNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Tenant-facing support tickets API.
 *
 * Tickets live in the central database (so the super admin can see every
 * tenant's tickets in one place); each row is scoped to the current tenant
 * via tenant_id. Internal admin notes are never exposed here.
 */
class SupportController extends Controller
{
    private const MAX_ATTACHMENT_KB = 10240; // 10 MB

    /** Allowed attachment file types. */
    private const ALLOWED_ATTACHMENT_MIMES = 'jpg,jpeg,png,pdf';

    public function index(Request $request): JsonResponse
    {
        $this->authorizeForUser($request->user('api'), 'view', SupportTicket::class);

        $tenantId = tenant()->id;

        $query = SupportTicket::where('tenant_id', $tenantId);

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('ticket_number', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%");
            });
        }

        $perPage = (int) $request->input('limit', 10);

        $tickets = $query->withCount('publicMessages')
            ->orderByDesc('last_reply_at')
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', (int) $request->input('page', 1));

        $base = SupportTicket::where('tenant_id', $tenantId);

        return response()->json([
            'tickets'  => collect($tickets->items())->map(fn ($t) => $this->ticketSummary($t)),
            'totalRows' => $tickets->total(),
            'stats'    => [
                'open'     => (clone $base)->where('status', SupportTicket::STATUS_OPEN)->count(),
                'pending'  => (clone $base)->where('status', SupportTicket::STATUS_PENDING)->count(),
                'resolved' => (clone $base)->where('status', SupportTicket::STATUS_RESOLVED)->count(),
                'closed'   => (clone $base)->where('status', SupportTicket::STATUS_CLOSED)->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeForUser($request->user('api'), 'create', SupportTicket::class);

        $validated = $request->validate([
            'subject'       => ['required', 'string', 'max:255'],
            'category'      => ['required', 'in:' . implode(',', SupportTicket::CATEGORIES)],
            'priority'      => ['required', 'in:' . implode(',', SupportTicket::PRIORITIES)],
            'message'       => ['required', 'string'],
            'attachments'   => ['nullable', 'array'],
            'attachments.*' => ['file', 'mimes:' . self::ALLOWED_ATTACHMENT_MIMES, 'max:' . self::MAX_ATTACHMENT_KB],
        ]);

        $user = auth()->user();
        $authorName = $this->authorName($user);

        $ticket = SupportTicket::create([
            'tenant_id'         => tenant()->id,
            'opened_by_user_id' => $user?->id,
            'opened_by_name'    => $authorName,
            'opened_by_email'   => $user?->email,
            'subject'           => $validated['subject'],
            'category'          => $validated['category'],
            'priority'          => $validated['priority'],
            'status'            => SupportTicket::STATUS_OPEN,
            'last_reply_at'     => now(),
            'last_reply_by'     => SupportTicketMessage::AUTHOR_TENANT,
            'admin_unread'      => true,
            'tenant_unread'     => false,
        ]);

        $message = SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'author_type'       => SupportTicketMessage::AUTHOR_TENANT,
            'author_id'         => $user?->id,
            'author_name'       => $authorName,
            'is_internal_note'  => false,
            'body'              => $validated['message'],
        ]);

        $this->storeAttachments($request, $ticket, $message);

        SupportTicketNotificationService::ticketCreated($ticket, $message);

        return response()->json([
            'success' => true,
            'ticket'  => $this->ticketSummary($ticket),
        ], 201);
    }

    public function show(Request $request, $id): JsonResponse
    {
        $this->authorizeForUser($request->user('api'), 'view', SupportTicket::class);

        $ticket = $this->findTenantTicket($id);

        // Mark admin replies / status changes as seen by the tenant.
        if ($ticket->tenant_unread) {
            $ticket->update(['tenant_unread' => false]);
        }

        $ticket->load(['publicMessages.attachments', 'assignedAdmin']);

        return response()->json([
            'ticket' => $this->ticketDetail($ticket),
        ]);
    }

    public function reply(Request $request, $id): JsonResponse
    {
        $this->authorizeForUser($request->user('api'), 'create', SupportTicket::class);

        $ticket = $this->findTenantTicket($id);

        $validated = $request->validate([
            'message'       => ['required', 'string'],
            'attachments'   => ['nullable', 'array'],
            'attachments.*' => ['file', 'mimes:' . self::ALLOWED_ATTACHMENT_MIMES, 'max:' . self::MAX_ATTACHMENT_KB],
        ]);

        $user = auth()->user();
        $authorName = $this->authorName($user);

        $message = SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'author_type'       => SupportTicketMessage::AUTHOR_TENANT,
            'author_id'         => $user?->id,
            'author_name'       => $authorName,
            'is_internal_note'  => false,
            'body'              => $validated['message'],
        ]);

        $this->storeAttachments($request, $ticket, $message);

        // A tenant reply re-opens a resolved/closed ticket as pending.
        $newStatus = $ticket->isClosed() ? SupportTicket::STATUS_PENDING : $ticket->status;

        $ticket->update([
            'status'        => $newStatus,
            'last_reply_at' => now(),
            'last_reply_by' => SupportTicketMessage::AUTHOR_TENANT,
            'admin_unread'  => true,
            'tenant_unread' => false,
        ]);

        SupportTicketNotificationService::tenantReplied($ticket, $message);

        return response()->json([
            'success' => true,
            'message' => $this->messagePayload($message->load('attachments')),
        ], 201);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /** Fetch a ticket ensuring it belongs to the current tenant. */
    private function findTenantTicket($id): SupportTicket
    {
        return SupportTicket::where('tenant_id', tenant()->id)->findOrFail($id);
    }

    private function authorName($user): string
    {
        if (! $user) {
            return 'User';
        }

        $name = trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? ''));

        return $name !== '' ? $name : ($user->username ?? $user->email ?? 'User');
    }

    private function storeAttachments(Request $request, SupportTicket $ticket, SupportTicketMessage $message): void
    {
        if (! $request->hasFile('attachments')) {
            return;
        }

        $relativeDir = upload_path('support/' . $ticket->id);  // images/tenants/{id}/support/{ticket}
        $absoluteDir = public_path($relativeDir);

        if (! File::isDirectory($absoluteDir)) {
            File::makeDirectory($absoluteDir, 0755, true);
        }

        foreach ($request->file('attachments') as $file) {
            // Capture metadata before move() — afterwards the temp file is gone
            // and getSize() would stat a missing path and throw.
            $originalName = $file->getClientOriginalName();
            $mimeType     = $file->getClientMimeType();
            $size         = $file->getSize();

            $filename = time() . '_' . Str::random(10) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '', $originalName);
            $file->move($absoluteDir, $filename);

            SupportTicketAttachment::create([
                'support_ticket_id'         => $ticket->id,
                'support_ticket_message_id' => $message->id,
                'file_path'                 => $relativeDir . '/' . $filename,
                'original_name'             => $originalName,
                'mime_type'                 => $mimeType,
                'size'                      => $size,
            ]);
        }
    }

    private function ticketSummary(SupportTicket $ticket): array
    {
        return [
            'id'             => $ticket->id,
            'ticket_number'  => $ticket->ticket_number,
            'subject'        => $ticket->subject,
            'category'       => $ticket->category,
            'priority'       => $ticket->priority,
            'status'         => $ticket->status,
            'tenant_unread'  => (bool) $ticket->tenant_unread,
            'messages_count' => $ticket->public_messages_count ?? null,
            'last_reply_at'  => optional($ticket->last_reply_at)->toIso8601String(),
            'last_reply_by'  => $ticket->last_reply_by,
            'created_at'     => optional($ticket->created_at)->toIso8601String(),
        ];
    }

    private function ticketDetail(SupportTicket $ticket): array
    {
        return array_merge($this->ticketSummary($ticket), [
            'assigned_admin' => $ticket->assignedAdmin->name ?? null,
            'messages'       => $ticket->publicMessages->map(fn ($m) => $this->messagePayload($m))->values(),
        ]);
    }

    private function messagePayload(SupportTicketMessage $message): array
    {
        return [
            'id'          => $message->id,
            'author_type' => $message->author_type,
            'author_name' => $message->author_name,
            'body'        => $message->body,
            'created_at'  => optional($message->created_at)->toIso8601String(),
            'attachments' => $message->attachments->map(fn ($a) => [
                'id'            => $a->id,
                'original_name' => $a->original_name ?? basename($a->file_path),
                'url'           => $a->url,
                'size'          => $a->size,
                'is_image'      => $a->isImage(),
            ])->values(),
        ];
    }
}
