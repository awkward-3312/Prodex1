<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\CentralUser;
use App\Models\Central\SupportTicket;
use App\Models\Central\SupportTicketAttachment;
use App\Models\Central\SupportTicketMessage;
use App\Services\SupportTicketNotificationService;
use App\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SupportController extends Controller
{
    /** Max size per uploaded attachment (KB). */
    private const MAX_ATTACHMENT_KB = 10240; // 10 MB

    /** Allowed attachment file types. */
    private const ALLOWED_ATTACHMENT_MIMES = 'jpg,jpeg,png,pdf';

    public function index(Request $request): View
    {
        $query = SupportTicket::with(['tenant', 'assignedAdmin']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('ticket_number', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('opened_by_name', 'like', "%{$search}%")
                    ->orWhere('opened_by_email', 'like', "%{$search}%")
                    ->orWhere('tenant_id', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }

        if ($priority = $request->input('priority')) {
            $query->where('priority', $priority);
        }

        if ($tenant = $request->input('tenant_id')) {
            $query->where('tenant_id', $tenant);
        }

        if ($assigned = $request->input('assigned_to')) {
            if ($assigned === 'unassigned') {
                $query->whereNull('assigned_to');
            } else {
                $query->where('assigned_to', $assigned);
            }
        }

        $tickets = $query->orderByRaw("FIELD(status, 'open','pending','resolved','closed')")
            ->orderByDesc('last_reply_at')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'open'     => SupportTicket::where('status', SupportTicket::STATUS_OPEN)->count(),
            'pending'  => SupportTicket::where('status', SupportTicket::STATUS_PENDING)->count(),
            'resolved' => SupportTicket::where('status', SupportTicket::STATUS_RESOLVED)->count(),
            'closed'   => SupportTicket::where('status', SupportTicket::STATUS_CLOSED)->count(),
            'unread'   => SupportTicket::where('admin_unread', true)->count(),
        ];

        $admins = CentralUser::orderBy('name')->get(['id', 'name']);

        return view('central.super.support.index', compact('tickets', 'stats', 'admins'));
    }

    public function create(): View
    {
        $tenants = Tenant::orderBy('created_at', 'desc')->get();
        $admins = CentralUser::orderBy('name')->get(['id', 'name']);

        return view('central.super.support.create', compact('tenants', 'admins'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'tenant_id'     => ['required', 'string', 'exists:central.tenants,id'],
            'subject'       => ['required', 'string', 'max:255'],
            'category'      => ['required', 'in:' . implode(',', SupportTicket::CATEGORIES)],
            'priority'      => ['required', 'in:' . implode(',', SupportTicket::PRIORITIES)],
            'assigned_to'   => ['nullable', 'exists:central.central_users,id'],
            'message'       => ['required', 'string'],
            'attachments'   => ['nullable', 'array'],
            'attachments.*' => ['file', 'mimes:' . self::ALLOWED_ATTACHMENT_MIMES, 'max:' . self::MAX_ATTACHMENT_KB],
        ]);

        $tenant = Tenant::findOrFail($validated['tenant_id']);
        $admin = Auth::guard('central')->user();

        $ticket = SupportTicket::create([
            'tenant_id'         => $tenant->id,
            'opened_by_user_id' => null,
            'opened_by_name'    => $tenant->company_name ?? $tenant->name ?? $tenant->id,
            'opened_by_email'   => $tenant->admin_email,
            'subject'           => $validated['subject'],
            'category'          => $validated['category'],
            'priority'          => $validated['priority'],
            'status'            => SupportTicket::STATUS_OPEN,
            'assigned_to'       => $validated['assigned_to'] ?? null,
            'last_reply_at'     => now(),
            'last_reply_by'     => SupportTicketMessage::AUTHOR_ADMIN,
            'admin_unread'      => false,
            'tenant_unread'     => true,   // surface the new ticket to the tenant
        ]);

        $message = SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'author_type'       => SupportTicketMessage::AUTHOR_ADMIN,
            'author_id'         => $admin->id,
            'author_name'       => $admin->name,
            'is_internal_note'  => false,
            'body'              => $validated['message'],
        ]);

        $this->storeAttachments($request, $ticket, $message);

        SupportTicketNotificationService::ticketCreatedByAdmin($ticket, $message);

        return redirect()
            ->route('super.support.show', $ticket)
            ->with('success', __('super.support.created'));
    }

    public function show(SupportTicket $ticket): View
    {
        $ticket->load([
            'tenant',
            'assignedAdmin',
            'messages.attachments',
        ]);

        // Mark tenant-side activity as seen by the admin.
        if ($ticket->admin_unread) {
            $ticket->update(['admin_unread' => false]);
        }

        $admins = CentralUser::orderBy('name')->get(['id', 'name']);

        return view('central.super.support.show', compact('ticket', 'admins'));
    }

    public function reply(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $validated = $request->validate([
            'body'          => ['required', 'string'],
            'attachments'   => ['nullable', 'array'],
            'attachments.*' => ['file', 'mimes:' . self::ALLOWED_ATTACHMENT_MIMES, 'max:' . self::MAX_ATTACHMENT_KB],
        ]);

        $admin = Auth::guard('central')->user();

        $message = SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'author_type'       => SupportTicketMessage::AUTHOR_ADMIN,
            'author_id'         => $admin->id,
            'author_name'       => $admin->name,
            'is_internal_note'  => false,
            'body'              => $validated['body'],
        ]);

        $this->storeAttachments($request, $ticket, $message);

        // A reply on a resolved/closed ticket re-opens it as pending.
        $newStatus = $ticket->isClosed() ? SupportTicket::STATUS_PENDING : $ticket->status;

        $ticket->update([
            'status'        => $newStatus,
            'last_reply_at' => now(),
            'last_reply_by' => SupportTicketMessage::AUTHOR_ADMIN,
            'tenant_unread' => true,   // tenant has an unseen admin reply
            'admin_unread'  => false,
        ]);

        SupportTicketNotificationService::adminReplied($ticket, $message);

        return back()->with('success', __('super.support.reply_sent'));
    }

    public function note(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string'],
        ]);

        $admin = Auth::guard('central')->user();

        SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'author_type'       => SupportTicketMessage::AUTHOR_ADMIN,
            'author_id'         => $admin->id,
            'author_name'       => $admin->name,
            'is_internal_note'  => true,
            'body'              => $validated['body'],
        ]);

        // Internal notes never notify the tenant and don't touch unread flags.
        return back()->with('success', __('super.support.note_added'));
    }

    public function updateStatus(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:' . implode(',', SupportTicket::STATUSES)],
        ]);

        $status = $validated['status'];

        if ($status === $ticket->status) {
            return back()->with('info', __('super.support.status_unchanged'));
        }

        $ticket->update([
            'status'      => $status,
            'resolved_at' => $status === SupportTicket::STATUS_RESOLVED ? now() : null,
            'closed_at'   => $status === SupportTicket::STATUS_CLOSED ? now() : null,
            'tenant_unread' => true, // surface the status change to the tenant
        ]);

        SupportTicketNotificationService::statusChanged($ticket);

        return back()->with('success', __('super.support.status_updated'));
    }

    public function assign(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $validated = $request->validate([
            'assigned_to' => ['nullable', 'exists:central.central_users,id'],
        ]);

        $ticket->update(['assigned_to' => $validated['assigned_to'] ?: null]);

        return back()->with('success', __('super.support.assignment_updated'));
    }

    public function downloadAttachment(SupportTicketAttachment $attachment): BinaryFileResponse
    {
        $absolute = public_path($attachment->file_path);

        abort_unless(File::exists($absolute), 404);

        return response()->download($absolute, $attachment->original_name ?: basename($absolute));
    }

    /**
     * Persist uploaded files for an admin message under public/images/super/support/{ticket}.
     */
    private function storeAttachments(Request $request, SupportTicket $ticket, SupportTicketMessage $message): void
    {
        if (! $request->hasFile('attachments')) {
            return;
        }

        $relativeDir = 'images/super/support/' . $ticket->id;
        $absoluteDir = public_path($relativeDir);

        if (! File::isDirectory($absoluteDir)) {
            File::makeDirectory($absoluteDir, 0755, true);
        }

        foreach ($request->file('attachments') as $file) {
            $filename = time() . '_' . Str::random(10) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '', $file->getClientOriginalName());
            $file->move($absoluteDir, $filename);

            SupportTicketAttachment::create([
                'support_ticket_id'         => $ticket->id,
                'support_ticket_message_id' => $message->id,
                'file_path'                 => $relativeDir . '/' . $filename,
                'original_name'             => $file->getClientOriginalName(),
                'mime_type'                 => $file->getClientMimeType(),
                'size'                      => $file->getSize(),
            ]);
        }
    }
}
