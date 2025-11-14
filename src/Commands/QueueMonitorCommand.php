<?php

namespace Doppar\Queue\Commands;

use Phaseolies\Console\Schedule\Command;
use Doppar\Queue\Models\QueueJob;
use Doppar\Queue\Models\FailedJob;

class QueueMonitorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $name = 'queue:monitor';

    /**
     * The description of the console command.
     *
     * @var string
     */
    protected $description = 'Monitor queue statistics';

    /**
     * Execute the console command.
     *
     * @return int
     */
    protected function handle(): int
    {
        $queues = QueueJob::groupBy('queue')->pluck('queue');

        // Create table for queue statistics
        $table = $this->createTable();
        $table->setHeaders(['Queue', 'Pending', 'Processing']);

        foreach ($queues ?? [] as $queue) {
            $pending = QueueJob::where('queue', $queue)
                ->whereNull('reserved_at')
                ->count();

            $processing = QueueJob::where('queue', $queue)
                ->whereNotNull('reserved_at')
                ->count();

            $table->addRow([
                $queue,
                $pending,
                $processing,
            ]);
        }

        // Render queue table
        $this->newLine();
        $this->info("Queue Statistics");
        $table->render();

        // Failed jobs table
        $failedCount = FailedJob::count();

        $failedTable = $this->createTable();
        $failedTable->setHeaders(['Metric', 'Value']);
        $failedTable->addRow(['Failed Jobs', $failedCount]);

        $this->info("\nFailed Jobs Summary");
        $failedTable->render();

        return Command::SUCCESS;
    }
}
