<?php

namespace Doppar\Queue\Commands;

use Phaseolies\Console\Schedule\Command;
use Doppar\Queue\Commands\Concerns\ReadsOptions;
use Doppar\Queue\QueueManager;
use Doppar\Queue\Support\FailedJobRecord;

class QueueRetryCommand extends Command
{
    use ReadsOptions;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $name = 'queue:retry {--id=} {--connection=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retry failed job(s) by ID or all if no ID is provided';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $manager = app(QueueManager::class);
        $connection = $this->stringOption('connection');
        $id = $this->stringOption('id');

        if ($id) {
            $record = $manager->connection($connection)->findFailed($id);

            if ($record === null) {
                $this->error("Failed job with ID {$id} not found.");
                return Command::FAILURE;
            }

            return $this->retryFailedJob($manager, $record, $connection) ? Command::SUCCESS : Command::FAILURE;
        }

        foreach ($manager->connection($connection)->failedJobs() as $record) {
            $this->retryFailedJob($manager, $record, $connection);
        }

        return Command::SUCCESS;
    }

    /**
     * Push one failed job back onto its queue.
     *
     * @param QueueManager $manager
     * @param FailedJobRecord $record
     * @param string|null $connection
     * @return bool
     */
    protected function retryFailedJob(QueueManager $manager, FailedJobRecord $record, ?string $connection): bool
    {
        try {
            $job = $manager->unserializeJob($record->payload);
            $jobClass = get_class($job);

            if (!$manager->retryFailed($record, $connection)) {
                $this->error("✖ Job ID {$record->id} was not requeued (a unique job with the same key is already queued).");
                return false;
            }

            $this->info("✔ Retried job [{$jobClass}] (ID: {$record->id})");
            return true;
        } catch (\Throwable $e) {
            $this->error("✖ Failed to retry job ID {$record->id}: " . $e->getMessage());
            return false;
        }
    }
}
