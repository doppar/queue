<?php

namespace Doppar\Queue\Commands;

use Phaseolies\Console\Schedule\Command;
use Doppar\Queue\Models\FailedJob;

class QueueFlushCommand extends Command
{
    /**
     * The name of the console command.
     *
     * @var string
     */
    protected $name = 'queue:flush {--id=}';

    /**
     * The command description.
     *
     * @var string
     */
    protected $description = 'Delete failed job(s) by ID or all if no ID is provided';

    /**
     * Execute the console command
     * Example: php pool queue:flush --id=1
     *
     * @return int
     */
    public function handle(): int
    {
        $id = $this->option('id');

        if ($id) {
            return $this->flushJobById($id);
        }

        FailedJob::query()
            ->cursor(function (FailedJob $failedJob) {
                $failedJob->delete();
                $this->info("✔ Job with ID {$failedJob->id} has been deleted.");
            });

        return Command::SUCCESS;
    }

    protected function flushJobById(int $id): int
    {
        $failedJob = FailedJob::find($id);

        if (!$failedJob) {
            $this->error("Failed job with ID {$id} not found.");
            return Command::FAILURE;
        }

        $failedJob->delete();
        $this->info("✔ Job with ID {$failedJob->id} has been deleted.");

        return Command::SUCCESS;
    }
}
