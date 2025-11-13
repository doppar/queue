<?php

namespace Doppar\Queue;

use Doppar\Queue\Commands\MakeJobCommand;
use Phaseolies\Providers\ServiceProvider;
use Doppar\Queue\QueueManager;
use Doppar\Queue\Commands\QueueRunCommand;
use Doppar\Queue\Commands\QueueRetryCommand;

class QueueServiceProvider extends ServiceProvider
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
            MakeJobCommand::class
        ]);
    }
}
