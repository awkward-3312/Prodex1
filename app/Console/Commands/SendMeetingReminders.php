<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use App\Notifications\MeetingReminderNotification;
use App\Tenant;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendMeetingReminders extends Command
{
    protected $signature = 'meetings:send-reminders';

    protected $description = 'Send reminder notifications (in-app + email) to participants before a meeting starts';

    public function handle()
    {
        // Meetings live in each tenant DB — this must run per tenant,
        // never on the central connection.
        foreach (Tenant::where('status', Tenant::STATUS_ACTIVE)->cursor() as $tenant) {
            try {
                $tenant->run(fn () => $this->processTenant($tenant));
            } catch (\Illuminate\Database\QueryException $e) {
                // Tenant DB not migrated for the meetings feature yet — skip quietly.
                if (str_contains($e->getMessage(), 'Base table or view not found')) {
                    continue;
                }
                Log::warning("meetings:send-reminders failed for tenant {$tenant->id}: {$e->getMessage()}");
            } catch (\Throwable $e) {
                Log::warning("meetings:send-reminders failed for tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        return 0;
    }

    protected function processTenant(Tenant $tenant): void
    {
        $now = Carbon::now();

        $meetings = Meeting::with('participants.user', 'organizer')
            ->where('status', 'scheduled')
            ->where('reminder_sent', false)
            ->whereDate('meeting_date', '>=', $now->copy()->subDay()->toDateString())
            ->whereDate('meeting_date', '<=', $now->copy()->addDay()->toDateString())
            ->get();

        $sentMeetings = 0;

        foreach ($meetings as $meeting) {
            $start = Carbon::parse($meeting->meeting_date->toDateString() . ' ' . $meeting->start_time);
            $reminderAt = $start->copy()->subMinutes((int) $meeting->reminder_minutes);

            // Fire when we are inside the reminder window (reminder time reached)
            // but the meeting has not started more than 5 minutes ago.
            if ($now->gte($reminderAt) && $now->lte($start->copy()->addMinutes(5))) {
                $recipients = collect();

                foreach ($meeting->participants as $participant) {
                    if ($participant->user) {
                        $recipients->push($participant->user);
                    }
                }
                if ($meeting->organizer) {
                    $recipients->push($meeting->organizer);
                }

                $recipients = $recipients->unique('id');

                foreach ($recipients as $user) {
                    $user->notify(new MeetingReminderNotification($meeting));
                }

                $meeting->update(['reminder_sent' => true]);
                $sentMeetings++;
            }
        }

        if ($sentMeetings > 0) {
            $this->info(sprintf('[%s] Processed %d meeting(s); sent reminders for %d.', $tenant->id, $meetings->count(), $sentMeetings));
        }
    }
}
