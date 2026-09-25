<?php

namespace Doppar\Queue\Commands;

use Phaseolies\Console\Schedule\Command;
use Doppar\Queue\Commands\Concerns\ReadsOptions;
use Doppar\Queue\QueueManager;

class QueueFailedCommand extends Command
{
    use ReadsOptions;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $name = 'queue:failed {--connection=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all failed jobs';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $failedJobs = app(QueueManager::class)->connection($this->stringOption('connection'))->failedJobs();

        if ($failedJobs === []) {
            $this->info("No failed jobs found.");
            return Command::SUCCESS;
        }

        // Create table
        $table = $this->createTable();
        $table->setHeaders(['ID', 'Job', 'Queue', 'Failed At']);

        foreach ($failedJobs as $job) {
            $data = @unserialize($job->payload);

            $jobClass = null;
            if (is_array($data) && isset($data['job']) && is_object($data['job'])) {
                $jobClass = get_class($data['job']);
            }

            $table->addRow([
                $job->id,
                $jobClass,
                $job->queue,
                date('Y-m-d H:i:s', $job->failedAt),
            ]);
        }

        // Render table
        $table->render();

        return Command::SUCCESS;
    }
}
