<?php

namespace Doppar\Queue\Commands;

use Phaseolies\Console\Schedule\Command;
use Doppar\Queue\QueueManager;
use Doppar\Queue\Models\FailedJob;

class QueueRetryCommand extends Command
{
    /**
     * The name of the console command.
     *
     * @var string
     */
    protected $name = 'queue:retry {--id=}';

    /**
     * The command description.
     *
     * @var string
     */
    protected $description = 'Retry failed job(s) by ID or all if no ID is provided';

    /**
     * Execute the console command
     * Example: php pool queue:retry --id=4
     *
     * @return int
     */
    public function handle(): int
    {
        $id = $this->option('id');
        $manager = app(QueueManager::class);

        if ($id) {
            return $this->retryJobById($manager, $id);
        }

        FailedJob::query()
            ->cursor(function (FailedJob $failedJob) use ($manager) {
                $this->retryFailedJob($manager, $failedJob);
            });

        return Command::SUCCESS;
    }

    protected function retryJobById(QueueManager $manager, int $id): int
    {
        $failedJob = FailedJob::find($id);

        if (!$failedJob) {
            $this->error("Failed job with ID {$id} not found.");
            return Command::FAILURE;
        }

        if ($this->retryFailedJob($manager, $failedJob)) {
            return Command::SUCCESS;
        }

        return Command::FAILURE;
    }

    protected function retryFailedJob(QueueManager $manager, FailedJob $failedJob): bool
    {
        try {
            $job = $manager->unserializeJob($failedJob->payload);
            $jobClass = get_class($job);

            // Reset attempts
            $job->attempts = 0;

            // Push back to queue
            $manager->push($job);

            // Delete from failed jobs
            $failedJob->delete();

            $this->info("✔ Retried job [{$jobClass}] (ID: {$failedJob->id})");
            return true;
        } catch (\Throwable $e) {
            $this->error("✖ Failed to retry job ID {$failedJob->id}: " . $e->getMessage());
            return false;
        }
    }
}
