<?php

use App\Console\Commands\ArchiveCompletedAppointmentsCommand;
use App\Services\AppointmentArchiver;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| One job: the nightly auto-archive of settled appointments.
|
| Runs at 02:00 rather than on the hour because that is the quietest hour for a
| salon booking form, and the sweep is a bulk UPDATE — cheap, but it should not
| be competing with a customer picking a slot at ten past nine on a Saturday.
|
| `withoutOverlapping()` because two runs of this in the same minute would each
| archive the same rows; the second would then count zero and quietly do nothing,
| which is the correct outcome but not a useful thing to happen twice. The lock
| expires on its own if a run dies, so a crash cannot wedge the schedule
| permanently.
|
| `onOneServer()` only matters once more than one node exists. It is here so
| adding a second does not silently double every sweep.
|
| Missed runs are not backfilled. Laravel has `schedule:run` firing each minute
| and only runs a task whose window has passed, so a server that was off at 02:00
| catches up on its next boot if it boots within the hour, and otherwise waits for
| tomorrow. For this job that is the right behaviour: the window is measured from
| `completed_at`, so a booking that aged past 30 days while the server was down is
| picked up by the next run regardless of when that was. Nothing is lost by
| skipping a night — the sweep is a query on a column, not a subscription.
|
| Requires the scheduler to be running. On Windows, where `schedule:work` and a
| cron entry are not available, `dev-up.bat` starts `schedule:work` alongside the
| server; see README.md.
*/

Schedule::command(ArchiveCompletedAppointmentsCommand::class)
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Archive completed appointments older than '.AppointmentArchiver::DAYS_BEFORE_AUTO_ARCHIVE.' days.');

Schedule::command('records:cleanup')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Delete notifications and archive appointments and activity logs older than '.AppointmentArchiver::DAYS_BEFORE_AUTO_ARCHIVE.' days.');