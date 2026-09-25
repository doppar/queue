<?php

namespace Doppar\Queue\Commands;

use Phaseolies\Console\Schedule\Command;
use Doppar\Queue\Commands\Concerns\ReadsOptions;
use Doppar\Queue\QueueManager;

class QueueMonitorCommand extends Command
{
    use ReadsOptions;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $name = 'queue:monitor {--connection=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor queue statistics';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $manager = app(QueueManager::class);
        $connection = $this->stringOption('connection');
        $driver = $manager->connection($connection);

        // Create table for queue statistics
        $table = $this->createTable();
        $table->setHeaders(['Queue', 'Ready', 'Delayed', 'Processing']);

        foreach ($driver->queues() as $queue) {
            $stats = $driver->stats($queue);

            $table->addRow([
                $queue,
                $stats['ready'],
                $stats['delayed'],
                $stats['reserved'],
            ]);
        }

        // Render queue table
        $this->newLine();
        $this->info("Queue Statistics (connection: " . ($connection ?? $manager->getDefaultConnection()) . ")");
        $table->render();

        // Failed jobs table
        $failedTable = $this->createTable();
        $failedTable->setHeaders(['Metric', 'Value']);
        $failedTable->addRow(['Failed Jobs', $driver->countFailed()]);

        $this->info("\nFailed Jobs Summary");
        $failedTable->render();

        return Command::SUCCESS;
    }
}
