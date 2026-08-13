<?php

namespace App\Notifications;

use App\Mail\CustomEmail;
use App\Models\Meeting;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class MeetingInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @var \App\Models\Meeting
     */
    protected $meeting;

    public function __construct(Meeting $meeting)
    {
        $this->meeting = $meeting;
    }

    public function via($notifiable)
    {
        return ['database', 'mail'];
    }

    protected function whenText(): string
    {
        $date = $this->meeting->meeting_date ? $this->meeting->meeting_date->format('Y-m-d') : '';
        $time = $this->meeting->start_time ? substr($this->meeting->start_time, 0, 5) : '';

        return trim($date . ' ' . $time);
    }

    public function toMail($notifiable)
    {
        $settings = Setting::whereNull('deleted_at')->first();
        $businessName = $settings ? $settings->CompanyName : config('app.name');

        $url = url('/app/meeting/details/' . $this->meeting->id);

        $subject = __('messages.Meeting invitation') . ': ' . $this->meeting->title;
        $body = __('messages.You have been invited to a meeting.') . '<br><br>'
            . '<strong>' . $this->meeting->title . '</strong><br>'
            . __('messages.When') . ': ' . $this->whenText() . '<br>'
            . ($this->meeting->type === 'online'
                ? __('messages.Link') . ': ' . ($this->meeting->meeting_link ?: '—') . '<br>'
                : __('messages.Location') . ': ' . ($this->meeting->location ?: '—') . '<br>')
            . '<br><a href="' . $url . '">' . __('messages.View meeting') . '</a>';

        return (new CustomEmail([
            'subject'      => $subject,
            'body'         => $body,
            'company_name' => $businessName,
        ]));
    }

    public function toArray($notifiable)
    {
        return [
            'type'         => 'meeting_invitation',
            'meeting_id'   => $this->meeting->id,
            'title'        => $this->meeting->title,
            'meeting_when' => $this->whenText(),
            'message'      => __('messages.Meeting invitation') . ': ' . $this->meeting->title . ' – ' . $this->whenText(),
        ];
    }
}
