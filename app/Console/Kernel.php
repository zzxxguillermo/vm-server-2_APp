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
        // Sincronizar padrón cada 2 horas
        $schedule->command('padron:sync')
            ->everyTwoHours()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->name('padron-sync')
            ->description('Sincronizar socios desde vmServer');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
