<?php

namespace Doppar\Queue;

use Doppar\Queue\Commands\MakeJobCommand;
use Phaseolies\Launchers\GhostableLauncher;
use Phaseolies\Launchers\ServiceLauncher;
use Doppar\Queue\QueueManager;
use Doppar\Queue\Commands\QueueRunCommand;
use Doppar\Queue\Commands\QueueRetryCommand;
use Doppar\Queue\Commands\QueueFlushCommand;
use Doppar\Queue\Commands\QueueFailedCommand;
use Doppar\Queue\Commands\QueueMonitorCommand;

class QueueLauncher extends ServiceLauncher implements GhostableLauncher
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfig(__DIR__ . '/../config/queue.php', 'queue');

        $this->app->singleton(QueueManager::class, fn(): QueueManager => new QueueManager());
        $this->app->alias(QueueManager::class, 'queue.worker');
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function launch(): void
    {
        $this->loadMigrations(__DIR__ . '/database/migrations');

        $this->publishes([
            __DIR__ . '/database/migrations' => schema_path('migrations'),
        ], 'migrations');

        $this->publishes([
            __DIR__ . '/../config/queue.php' => config_path('queue.php'),
        ], 'config');

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
            QueueManager::class,
        ];
    }
}
