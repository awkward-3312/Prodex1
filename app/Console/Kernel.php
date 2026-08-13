<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
        'App\Console\Commands\DatabaseBackUp',
        'App\Console\Commands\WooCommerceSync',
        'App\\Console\\Commands\\WooCommercePushProducts',
        'App\Console\Commands\SendMeetingReminders',
        'App\Console\Commands\ProcessScheduledCampaigns',
    ];

    /**
     * Define the application's command schedule.
     *
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {

        $schedule->command('database:backup');

        $schedule->command('subscriptions:check-expiry')->daily();

        $schedule->command('tenant:cleanup-inactive')->daily();

        $schedule->command('assets:check-validation-due')->daily();

        $schedule->command('meetings:send-reminders')->everyMinute()->withoutOverlapping();

        $schedule->command('marketing:process-scheduled')->everyMinute()->withoutOverlapping();

        /**
         * Shared hosting friendly queue processing:
         * Each minute, drain pending jobs (including WooCommerce sync batches) until the
         * queues are empty or ~50s elapse, then exit so the next minute can resume.
         *
         * IMPORTANT: You must have a cron that runs `php artisan schedule:run` every minute.
         * The WooCommerce queues are listed explicitly because Woo sync batches re-dispatch
         * onto fixed queues (woocommerce-sync / woocommerce-stock); without them the sync
         * gets stuck at "queued_next_batch" when no Supervisor/systemd worker is running.
         *
         * For higher throughput, run a persistent worker via Supervisor instead (see
         * deploy/supervisor/stocky-queue-worker.conf). Both can coexist safely.
         */
        $schedule->command('queue:work database --stop-when-empty --max-time=50 --queue=woocommerce-sync,woocommerce-stock,default --sleep=1 --tries=1 --timeout='.((int) env('QUEUE_WORKER_TIMEOUT', 1200)))
            ->everyMinute()
            ->withoutOverlapping()
            ->evenInMaintenanceMode();

    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
