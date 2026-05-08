<?php

namespace Doppar\Queue;

use Doppar\Queue\Commands\MakeJobCommand;
use Phaseolies\Providers\GhostableProvider;
use Phaseolies\Providers\ServiceProvider;
use Doppar\Queue\QueueManager;
use Doppar\Queue\Commands\QueueRunCommand;
use Doppar\Queue\Commands\QueueRetryCommand;
use Doppar\Queue\Commands\QueueFlushCommand;
use Doppar\Queue\Commands\QueueFailedCommand;
use Doppar\Queue\Commands\QueueMonitorCommand;

class QueueServiceProvider extends ServiceProvider implements GhostableProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton('queue.worker', QueueManager::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->loadMigrations(__DIR__ . '/database/migrations');

        $this->publishes([
            __DIR__ . '/database/migrations' => database_path('migrations'),
        ], 'migrations');

        $this->commands([
            QueueRunCommand::class,
            QueueRetryCommand::class,
            QueueFlushCommand::class,
            QueueFailedCommand::class,
            QueueMonitorCommand::class,
            MakeJobCommand::class
        ]);
    }

    /**
     * Get the services that should ghost-load this provider.
     *
     * @return array<int, string>
     */
    public function ghosts(): array
    {
        return [
            'queue.worker',
        ];
    }
}
