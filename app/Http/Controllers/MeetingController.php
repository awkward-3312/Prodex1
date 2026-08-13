<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Models\MeetingActivityLog;
use App\Models\MeetingAttachment;
use App\Models\MeetingNote;
use App\Models\MeetingParticipant;
use App\Models\Permission;
use App\Models\User;
use App\Notifications\MeetingInvitationNotification;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MeetingController extends Controller
{
    /**
     * Ensure the authenticated user owns the given permission (via any of its roles).
     * Used for abilities that are not tied to an Eloquent model policy
     * (manage attendance, view reports). Aborts with 403 otherwise.
     */
    private function checkPermission(Request $request, string $permissionName): void
    {
        $user = $request->user('api');
        $permission = Permission::where('name', $permissionName)->first();

        if (! $user || ! $permission || ! $user->hasRole($permission->roles)) {
            abort(403, 'This action is unauthorized.');
        }
    }

    /**
     * Write an audit entry for a meeting.
     */
    private function logActivity(Request $request, int $meetingId, string $action, ?string $description = null): void
    {
        MeetingActivityLog::create([
            'meeting_id'  => $meetingId,
            'user_id'     => $request->user('api') ? $request->user('api')->id : null,
            'action'      => $action,
            'description' => $description,
        ]);
    }

    /**
     * Store an uploaded meeting file under public/images/meetings and return its
     * public-relative path. Stocky serves uploads from public/images directly.
     */
    private function storeUpload($file): string
    {
        $destination = public_path('images/meetings');
        if (! is_dir($destination)) {
            mkdir($destination, 0755, true);
        }
        $filename = Str::random(20) . '.' . $file->getClientOriginalExtension();
        $file->move($destination, $filename);

        return 'images/meetings/' . $filename;
    }

    /**
     * Sync the participant list for a meeting from an array of user ids.
     * Existing participants keep their invitation/attendance status; removed
     * ones are detached; new ones are created with default statuses.
     */
    private function syncParticipants(Meeting $meeting, array $userIds): void
    {
        $userIds = array_values(array_unique(array_filter($userIds)));

        $existing = $meeting->participants()->pluck('user_id')->toArray();

        // Remove participants no longer selected.
        $toRemove = array_diff($existing, $userIds);
        if (! empty($toRemove)) {
            $meeting->participants()->whereIn('user_id', $toRemove)->delete();
        }

        // Add newly selected participants.
        $toAdd = array_diff($userIds, $existing);
        foreach ($toAdd as $uid) {
            MeetingParticipant::create([
                'meeting_id' => $meeting->id,
                'user_id'    => $uid,
            ]);
        }
    }

    // ======================== DASHBOARD ========================

    public function dashboard(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Meeting::class);

        $today = now()->toDateString();

        $stats = [
            'total_meetings'     => Meeting::count(),
            'upcoming_meetings'  => Meeting::where('status', 'scheduled')->whereDate('meeting_date', '>=', $today)->count(),
            'completed_meetings' => Meeting::where('status', 'completed')->count(),
            'ongoing_meetings'   => Meeting::where('status', 'ongoing')->count(),
            'cancelled_meetings' => Meeting::where('status', 'cancelled')->count(),
            'today_meetings'     => Meeting::whereDate('meeting_date', $today)->count(),
        ];

        $upcoming_meetings = Meeting::withCount('participants')
            ->whereDate('meeting_date', '>=', $today)
            ->whereIn('status', ['scheduled', 'ongoing'])
            ->orderBy('meeting_date')
            ->orderBy('start_time')
            ->limit(10)
            ->get();

        $by_status = Meeting::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $by_type = Meeting::select('type', DB::raw('count(*) as count'))
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        return response()->json([
            'stats'             => $stats,
            'upcoming_meetings' => $upcoming_meetings,
            'by_status'         => $by_status,
            'by_type'           => $by_type,
        ]);
    }

    // ======================== CALENDAR ========================

    public function calendar(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Meeting::class);

        $query = Meeting::query();

        if ($request->filled('from')) {
            $query->whereDate('meeting_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('meeting_date', '<=', $request->to);
        }

        $meetings = $query->orderBy('meeting_date')->orderBy('start_time')->get([
            'id', 'title', 'meeting_date', 'start_time', 'end_time', 'type', 'status',
        ]);

        return response()->json(['meetings' => $meetings]);
    }

    // ======================== MEETINGS ========================

    public function meetings_index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Meeting::class);

        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField ?: 'id';
        $dir = $request->SortType ?: 'desc';

        $meetings = Meeting::with('organizer:id,firstname,lastname,username')
            ->withCount('participants')
            ->where(function ($query) use ($request) {
                $query->when($request->filled('status'), function ($q) use ($request) {
                    $q->where('status', $request->status);
                });
                $query->when($request->filled('type'), function ($q) use ($request) {
                    $q->where('type', $request->type);
                });
                $query->when($request->filled('organizer_id'), function ($q) use ($request) {
                    $q->where('organizer_id', $request->organizer_id);
                });
                $query->when($request->filled('date_from'), function ($q) use ($request) {
                    $q->whereDate('meeting_date', '>=', $request->date_from);
                });
                $query->when($request->filled('date_to'), function ($q) use ($request) {
                    $q->whereDate('meeting_date', '<=', $request->date_to);
                });
                $query->when($request->filled('participant_id'), function ($q) use ($request) {
                    $q->whereHas('participants', function ($qq) use ($request) {
                        $qq->where('user_id', $request->participant_id);
                    });
                });
                $query->when($request->filled('search'), function ($q) use ($request) {
                    $q->where('title', 'LIKE', "%{$request->search}%");
                });
            });

        $totalRows = $meetings->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }

        $meetings = $meetings->offset($offSet)->limit($perPage)->orderBy($order, $dir)->get();

        return response()->json([
            'meetings'  => $meetings,
            'totalRows' => $totalRows,
        ]);
    }

    public function meetings_all()
    {
        return response()->json(
            Meeting::orderBy('meeting_date', 'desc')->get(['id', 'title', 'meeting_date', 'status'])
        );
    }

    public function meetings_show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Meeting::class);

        $meeting = Meeting::with([
            'organizer:id,firstname,lastname,username,email',
            'participants.user:id,firstname,lastname,username,email,avatar',
            'notes.assignee:id,firstname,lastname,username',
            'attachments',
            'logs.user:id,firstname,lastname,username',
        ])->findOrFail($id);

        // Most recent activity first.
        $meeting->setRelation('logs', $meeting->logs->sortByDesc('created_at')->values());

        return response()->json(['meeting' => $meeting]);
    }

    public function meetings_store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Meeting::class);

        $request->validate([
            'title'        => 'required|string|max:255',
            'meeting_date' => 'required|date',
            'start_time'   => 'required',
            'type'         => 'required|in:physical,online',
        ]);

        $userId = $request->user('api') ? $request->user('api')->id : null;

        $meeting = Meeting::create([
            'title'            => $request->title,
            'description'      => $request->description,
            'agenda'           => $request->agenda,
            'meeting_date'     => $request->meeting_date,
            'start_time'       => $request->start_time,
            'end_time'         => $request->end_time,
            'location'         => $request->location,
            'type'             => $request->type,
            'status'           => $request->status ?: 'scheduled',
            'platform'         => $request->platform,
            'meeting_link'     => $request->meeting_link,
            'reminder_minutes' => $request->filled('reminder_minutes') ? (int) $request->reminder_minutes : 30,
            'organizer_id'     => $request->filled('organizer_id') ? $request->organizer_id : $userId,
            'created_by'       => $userId,
        ]);

        $this->syncParticipants($meeting, (array) $request->participants);

        $this->logActivity($request, $meeting->id, 'created', 'Meeting created');

        return response()->json(['success' => true, 'id' => $meeting->id]);
    }

    public function meetings_update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Meeting::class);

        $request->validate([
            'title'        => 'required|string|max:255',
            'meeting_date' => 'required|date',
            'start_time'   => 'required',
            'type'         => 'required|in:physical,online',
        ]);

        $meeting = Meeting::findOrFail($id);

        $meeting->update([
            'title'            => $request->title,
            'description'      => $request->description,
            'agenda'           => $request->agenda,
            'meeting_date'     => $request->meeting_date,
            'start_time'       => $request->start_time,
            'end_time'         => $request->end_time,
            'location'         => $request->location,
            'type'             => $request->type,
            'status'           => $request->status ?: $meeting->status,
            'platform'         => $request->platform,
            'meeting_link'     => $request->meeting_link,
            'reminder_minutes' => $request->filled('reminder_minutes') ? (int) $request->reminder_minutes : $meeting->reminder_minutes,
            'organizer_id'     => $request->filled('organizer_id') ? $request->organizer_id : $meeting->organizer_id,
        ]);

        if ($request->has('participants')) {
            $this->syncParticipants($meeting, (array) $request->participants);
        }

        $this->logActivity($request, $meeting->id, 'updated', 'Meeting updated');

        return response()->json(['success' => true]);
    }

    public function meetings_update_status(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Meeting::class);

        $request->validate(['status' => 'required|in:scheduled,ongoing,completed,cancelled']);

        $meeting = Meeting::findOrFail($id);
        $meeting->update(['status' => $request->status]);

        $this->logActivity($request, $meeting->id, 'status_changed', 'Status changed to ' . $request->status);

        return response()->json(['success' => true]);
    }

    public function meetings_destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Meeting::class);

        Meeting::whereId($id)->update(['deleted_at' => Carbon::now()]);

        return response()->json(['success' => true]);
    }

    public function meetings_delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Meeting::class);

        foreach ($request->selectedIds as $id) {
            Meeting::whereId($id)->update(['deleted_at' => Carbon::now()]);
        }

        return response()->json(['success' => true]);
    }

    // ======================== INVITATIONS / NOTIFICATIONS ========================

    public function meetings_send_invitations(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Meeting::class);

        $meeting = Meeting::with('participants.user')->findOrFail($id);

        $sent = 0;
        foreach ($meeting->participants as $participant) {
            if ($participant->user) {
                $participant->user->notify(new MeetingInvitationNotification($meeting));
                $participant->update([
                    'is_notified' => true,
                    'notified_at' => now(),
                ]);
                $sent++;
            }
        }

        $this->logActivity($request, $meeting->id, 'invitations_sent', $sent . ' invitation(s) sent');

        return response()->json(['success' => true, 'sent' => $sent]);
    }

    // ======================== ATTENDANCE ========================

    public function attendance_update(Request $request, $id)
    {
        $this->checkPermission($request, 'meeting_attendance');

        $meeting = Meeting::findOrFail($id);

        $request->validate([
            'attendance'                       => 'required|array',
            'attendance.*.id'                  => 'required|exists:meeting_participants,id',
            'attendance.*.attendance_status'   => 'required|in:pending,present,absent,late',
        ]);

        foreach ($request->attendance as $row) {
            MeetingParticipant::where('id', $row['id'])
                ->where('meeting_id', $meeting->id)
                ->update(['attendance_status' => $row['attendance_status']]);
        }

        $this->logActivity($request, $meeting->id, 'attendance_updated', 'Attendance updated');

        return response()->json(['success' => true]);
    }

    // ======================== NOTES / MINUTES / ACTION ITEMS ========================

    public function notes_store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', Meeting::class);

        $request->validate([
            'meeting_id' => 'required|exists:meetings,id',
            'type'       => 'required|in:note,decision,action_item',
            'content'    => 'required|string',
        ]);

        MeetingNote::create([
            'meeting_id'  => $request->meeting_id,
            'type'        => $request->type,
            'content'     => $request->content,
            'assigned_to' => $request->assigned_to,
            'due_date'    => $request->due_date,
            'status'      => $request->status ?: 'open',
            'created_by'  => $request->user('api') ? $request->user('api')->id : null,
        ]);

        $this->logActivity($request, (int) $request->meeting_id, 'note_added', ucfirst(str_replace('_', ' ', $request->type)) . ' added');

        return response()->json(['success' => true]);
    }

    public function notes_update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Meeting::class);

        $request->validate([
            'type'    => 'required|in:note,decision,action_item',
            'content' => 'required|string',
        ]);

        $note = MeetingNote::findOrFail($id);
        $note->update($request->only(['type', 'content', 'assigned_to', 'due_date', 'status']));

        $this->logActivity($request, $note->meeting_id, 'note_updated', 'Note updated');

        return response()->json(['success' => true]);
    }

    public function notes_destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Meeting::class);

        $note = MeetingNote::findOrFail($id);
        $meetingId = $note->meeting_id;
        $note->update(['deleted_at' => Carbon::now()]);

        $this->logActivity($request, $meetingId, 'note_deleted', 'Note deleted');

        return response()->json(['success' => true]);
    }

    // ======================== ATTACHMENTS ========================

    public function attachments_store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', Meeting::class);

        $request->validate([
            'meeting_id' => 'required|exists:meetings,id',
            'file'       => 'required|file|max:10240',
        ]);

        $file = $request->file('file');
        $path = $this->storeUpload($file);

        MeetingAttachment::create([
            'meeting_id'  => $request->meeting_id,
            'file_name'   => $file->getClientOriginalName(),
            'file_path'   => $path,
            'file_type'   => $file->getClientOriginalExtension(),
            'file_size'   => $file->getSize(),
            'uploaded_by' => $request->user('api') ? $request->user('api')->id : null,
        ]);

        $this->logActivity($request, (int) $request->meeting_id, 'attachment_added', 'Attachment uploaded');

        return response()->json(['success' => true]);
    }

    public function attachments_destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Meeting::class);

        $attachment = MeetingAttachment::findOrFail($id);
        $meetingId = $attachment->meeting_id;

        $full = public_path($attachment->file_path);
        if ($attachment->file_path && is_file($full)) {
            @unlink($full);
        }
        $attachment->delete();

        $this->logActivity($request, $meetingId, 'attachment_deleted', 'Attachment deleted');

        return response()->json(['success' => true]);
    }

    // ======================== REPORTS ========================

    public function reports(Request $request)
    {
        $this->checkPermission($request, 'meeting_report');

        $period = $request->get('period', '6months');
        $dateFrom = match ($period) {
            '1month'  => now()->subMonth(),
            '3months' => now()->subMonths(3),
            '1year'   => now()->subYear(),
            default   => now()->subMonths(6),
        };

        $today = now()->toDateString();

        $summary = [
            'total'     => Meeting::count(),
            'upcoming'  => Meeting::where('status', 'scheduled')->whereDate('meeting_date', '>=', $today)->count(),
            'completed' => Meeting::where('status', 'completed')->count(),
            'cancelled' => Meeting::where('status', 'cancelled')->count(),
        ];

        $by_status = Meeting::select('status', DB::raw('count(*) as count'))
            ->where('created_at', '>=', $dateFrom)
            ->groupBy('status')
            ->pluck('count', 'status');

        $by_type = Meeting::select('type', DB::raw('count(*) as count'))
            ->where('created_at', '>=', $dateFrom)
            ->groupBy('type')
            ->pluck('count', 'type');

        $monthly_trend = Meeting::select(
                DB::raw('DATE_FORMAT(meeting_date, "%Y-%m") as month'),
                DB::raw('count(*) as total'),
                DB::raw('SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed'),
                DB::raw('SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END) as cancelled')
            )
            ->where('meeting_date', '>=', $dateFrom)
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        // Attendance statistics across participants of meetings in the period.
        $attendance = MeetingParticipant::select('attendance_status', DB::raw('count(*) as count'))
            ->whereHas('meeting', function ($q) use ($dateFrom) {
                $q->where('created_at', '>=', $dateFrom);
            })
            ->groupBy('attendance_status')
            ->pluck('count', 'attendance_status');

        $present = (int) ($attendance['present'] ?? 0);
        $late = (int) ($attendance['late'] ?? 0);
        $totalAttendance = array_sum($attendance->toArray());
        $attendance_rate = $totalAttendance > 0 ? round((($present + $late) / $totalAttendance) * 100, 1) : 0;

        $top_organizers = Meeting::select('organizer_id', DB::raw('count(*) as count'))
            ->with('organizer:id,firstname,lastname,username')
            ->where('created_at', '>=', $dateFrom)
            ->whereNotNull('organizer_id')
            ->groupBy('organizer_id')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        return response()->json([
            'summary'         => $summary,
            'by_status'       => $by_status,
            'by_type'         => $by_type,
            'monthly_trend'   => $monthly_trend,
            'attendance'      => $attendance,
            'attendance_rate' => $attendance_rate,
            'top_organizers'  => $top_organizers,
        ]);
    }
}
