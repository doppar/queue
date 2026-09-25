<?php

namespace Doppar\Queue\Commands;

use Phaseolies\Console\Schedule\Command;
use Doppar\Queue\Commands\Concerns\ReadsOptions;
use Doppar\Queue\QueueManager;

class QueueFlushCommand extends Command
{
    use ReadsOptions;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $name = 'queue:flush {--id=} {--connection=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete failed job(s) by ID or all if no ID is provided';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $driver = app(QueueManager::class)->connection($this->stringOption('connection'));
        $id = $this->stringOption('id');

        if ($id) {
            if (!$driver->forgetFailed($id)) {
                $this->error("Failed job with ID {$id} not found.");
                return Command::FAILURE;
            }

            $this->info("✔ Job with ID {$id} has been deleted.");

            return Command::SUCCESS;
        }

        $count = $driver->flushFailed();
        $this->info("✔ {$count} failed job(s) deleted.");

        return Command::SUCCESS;
    }
}
