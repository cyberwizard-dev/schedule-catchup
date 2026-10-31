<?php

namespace Cyberwizard\ScheduleCatchup\Console;

use Cyberwizard\ScheduleCatchup\Console\Commands\ScheduleCatchupCommand;
use Cyberwizard\ScheduleCatchup\Console\Commands\ScheduleHeartbeatCommand;
use Cyberwizard\ScheduleCatchup\Console\Commands\SchedulePruneCommand;
use Cyberwizard\ScheduleCatchup\Listeners\RecordScheduledTaskRun;
use Cyberwizard\ScheduleCatchup\Support\ScheduleReplayRegistry;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\ServiceProvider;

class ScheduleCatchupServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/schedule-catchup.php', 'schedule-catchup');

        Event::macro('replayOnCatchup', function (): static {
            ScheduleReplayRegistry::tag($this);

            return $this;
        });

        EventFacade::listen(ScheduledTaskStarting::class, [RecordScheduledTaskRun::class, 'starting']);
        EventFacade::listen(ScheduledTaskFinished::class, RecordScheduledTaskRun::class);

        $this->publishes([
            __DIR__.'/../../config/schedule-catchup.php' => config_path('schedule-catchup.php'),
        ], 'schedule-catchup-config');

        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        $this->publishesMigrations([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(ScheduleCatchupCommand::class)
                ->everyFiveMinutes()
                ->withoutOverlapping()
                ->onOneServer();

            $schedule->command(ScheduleHeartbeatCommand::class)
                ->everyFiveMinutes()
                ->withoutOverlapping()
                ->onOneServer();

            $schedule->command(SchedulePruneCommand::class)
                ->dailyAt('04:10')
                ->onOneServer();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                ScheduleCatchupCommand::class,
                ScheduleHeartbeatCommand::class,
                SchedulePruneCommand::class,
            ]);
        }
    }
}
