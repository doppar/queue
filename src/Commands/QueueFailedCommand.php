<?php

namespace Doppar\Queue\Commands;

use Phaseolies\Console\Schedule\Command;
use Doppar\Queue\Models\FailedJob;

class QueueFailedCommand extends Command
{
    /**
     * The name of the console command.
     *
     * @var string
     */
    protected $name = 'queue:failed';

    /**
     * The command description.
     *
     * @var string
     */
    protected $description = 'List all failed jobs';

    /**
     * Execute the console command
     * Example: php pool queue:failed
     *
     * @return int
     */
    public function handle(): int
    {
        $failedJobs = FailedJob::orderBy('failed_at', 'desc')->get();

        if ($failedJobs->isEmpty()) {
            $this->info("No failed jobs found.");
            return Command::SUCCESS;
        }

        // Create table
        $table = $this->createTable();
        $table->setHeaders(['ID', 'Job', 'Queue', 'Failed At']);

        foreach ($failedJobs as $job) {
            $payload = $job->payload;
            $data = unserialize($payload);

            $jobClass = null;
            if ($data && isset($data['job']) && is_object($data['job'])) {
                $jobClass = get_class($data['job']);
            }

            $failedAt = date('Y-m-d H:i:s', $job->failed_at);
            $table->addRow([
                $job->id,
                $jobClass,
                $job->queue,
                $failedAt
            ]);
        }

        // Render table
        $table->render();

        return Command::SUCCESS;
    }
}
