<?php

namespace App\Console\Commands;

use App\Models\AppointmentStatusHistory;
use App\Services\AppointmentArchiver;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Automatic 30-day cleanup and archiving for:
 *   1. Notifications (`notifications`) -> plain deletion for records older than 30 days
 *   2. Order Summary / Appointments (`appointments`) -> archived (`archived_at`) so
 *      Reports (`ReportService`) and Customer Order/Appointment History stay intact
 *   3. Activity Logs / Status History (`appointment_status_history`) -> archived
 *      (`archived_at`) so audit records remain in the database while hidden from
 *      active status history lists
 */
class ArchiveOldRecordsCommand extends Command
{
    protected $signature = 'records:cleanup
                            {--days=30 : Retention window in days}
                            {--dry-run : Report how many records would be processed without modifying data}';

    /**
     * The old command name, kept as an alias so existing deployments, cron
     * entries and muscle memory do not break when the clearer name is adopted.
     */
    protected $aliases = ['app:archive-old-records'];

    protected $description = 'Automatically delete notifications and archive appointments and activity logs older than 30 days.';

    public function handle(AppointmentArchiver $archiver): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        if ($this->option('dry-run')) {
            $notificationsCount = DatabaseNotification::query()
                ->where('created_at', '<=', $cutoff)
                ->count();
            $appointmentsCount = $archiver->sweepableCount(now(), $days);
            $logsCount = AppointmentStatusHistory::query()
                ->active()
                ->where('created_at', '<=', $cutoff)
                ->count();

            $this->components->info(
                "Dry run ({$days} days): {$notificationsCount} notifications would be deleted, "
                ."{$appointmentsCount} appointments and {$logsCount} activity logs would be archived."
            );

            return self::SUCCESS;
        }

        $deletedNotifications = 0;
        $archivedAppointments = 0;
        $archivedLogs = 0;

        DB::transaction(function () use (
            $archiver,
            $days,
            $cutoff,
            &$deletedNotifications,
            &$archivedAppointments,
            &$archivedLogs
        ) {
            // 1. Plain deletion for Notifications older than 30 days (chunked)
            DatabaseNotification::query()
                ->where('created_at', '<=', $cutoff)
                ->orderBy('id')
                ->chunk(200, function ($rows) use (&$deletedNotifications) {
                    $ids = $rows->pluck('id')->all();

                    if ($ids !== []) {
                        $deletedNotifications += DatabaseNotification::query()
                            ->whereIn('id', $ids)
                            ->delete();
                    }
                });

            // 2. Archive completed Order Summary / Appointment records older than 30 days
            //    (preserves revenue reports and customer order/appointment history)
            $archivedAppointments = $archiver->sweep(now(), $days);

            // 3. Archive Activity Logs / Appointment Status History older than 30 days (chunked)
            AppointmentStatusHistory::query()
                ->active()
                ->where('created_at', '<=', $cutoff)
                ->orderBy('id')
                ->chunkById(200, function ($rows) use (&$archivedLogs) {
                    $ids = $rows->pluck('id')->all();

                    if ($ids !== []) {
                        $archivedLogs += AppointmentStatusHistory::query()
                            ->whereIn('id', $ids)
                            ->whereNull('archived_at')
                            ->update(['archived_at' => now()]);
                    }
                });
        });

        Log::info('records:cleanup completed.', [
            'days' => $days,
            'cutoff' => $cutoff->toIso8601String(),
            'notifications_deleted' => $deletedNotifications,
            'appointments_archived' => $archivedAppointments,
            'activity_logs_archived' => $archivedLogs,
        ]);

        $this->components->info(
            "Processed records older than {$days} days: "
            ."{$deletedNotifications} notifications deleted, "
            ."{$archivedAppointments} appointments archived, "
            ."{$archivedLogs} activity logs archived."
        );

        return self::SUCCESS;
    }
}
