<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
  /**
   * Define the application's command schedule.
   */
  protected function schedule(Schedule $schedule): void
  {
    // Both run in background so a long proposal fetch (sync OpenAI calls) never
    // blocks the scheduler tick — otherwise the :00/:30 award check gets skipped.
    $schedule->command('proposals:fetch')
      ->everyMinute()
      ->runInBackground()
      ->withoutOverlapping(10);

    $schedule->command('bids:check-awards')
      ->everyThirtyMinutes()
      ->runInBackground()
      ->withoutOverlapping(25);

    $schedule->command('threads:escalate')
      ->everyTwoMinutes()
      ->runInBackground()
      ->withoutOverlapping(5);
  }

  /**
   * Register the commands for the application.
   */
  protected function commands(): void
  {
    $this->load(__DIR__ . '/Commands');

    require base_path('routes/console.php');
  }
}
