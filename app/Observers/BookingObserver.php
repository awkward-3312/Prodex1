<?php

namespace App\Observers;

use App\Jobs\SyncBookingToGoogleCalendar;
use App\Models\Booking;

class BookingObserver
{
    /**
     * Handle the Booking "created" event.
     */
    public function created(Booking $booking): void
    {
        SyncBookingToGoogleCalendar::dispatch(
            $booking->id,
            SyncBookingToGoogleCalendar::ACTION_UPSERT
        );
    }

    /**
     * Handle the Booking "updated" event.
     */
    public function updated(Booking $booking): void
    {
        SyncBookingToGoogleCalendar::dispatch(
            $booking->id,
            SyncBookingToGoogleCalendar::ACTION_UPSERT
        );
    }

    /**
     * Handle the Booking "deleted" event.
     */
    public function deleted(Booking $booking): void
    {
        if (empty($booking->google_calendar_event_id)) {
            return;
        }

        SyncBookingToGoogleCalendar::dispatch(
            $booking->id,
            SyncBookingToGoogleCalendar::ACTION_DELETE,
            $booking->google_calendar_event_id
        );
    }

    /**
     * Handle the Booking "restored" event.
     */
    public function restored(Booking $booking): void
    {
        SyncBookingToGoogleCalendar::dispatch(
            $booking->id,
            SyncBookingToGoogleCalendar::ACTION_UPSERT
        );
    }
}
