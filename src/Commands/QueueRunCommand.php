<?php

namespace Doppar\Queue\Commands;

use Phaseolies\Console\Schedule\Command;
use Doppar\Queue\QueueWorker;
use Doppar\Queue\QueueManager;

class QueueRunCommand extends Command
{
    /**
     * The name of the console command.
     *
     * @var string
     */
    protected $name = 'queue:run {--queue=default} {--sleep=3} {--memory=128} {--timeout=3600} {--limit=}';

    /**
     * The command description.
     *
     * @var string
     */
    protected $description = 'Process jobs on the queue one by one';

    /**
     * The queue manager instance.
     *
     * @var QueueManager
     */
    protected $manager;

    /**
     * The queue worker instance.
     *
     * @var QueueWorker
     */
    protected $worker;

    /**
     * Create a new command instance.
     *
     * @param QueueManager $manager
     */
    public function __construct(QueueManager $manager)
    {
        parent::__construct();
        $this->manager = $manager;
        $this->worker = new QueueWorker($manager);
    }

    /**
     * Execute the console command.
     * Example: php pool queue:run --queue=reports --sleep=10 --memory=1024 --timeout=3600 --limit=
     *
     * @return int
     */
    protected function handle(): int
    {
        return $this->withTiming(function () {
            $queue = $this->option('queue', 'default');
            $sleep = (int) $this->option('sleep', 3);
            $maxMemory = (int) $this->option('memory', 128);
            $maxTime = (int) $this->option('timeout', 3600);
            $maxLimit = $this->option('limit');

            $maxLimit = $maxLimit !== null ? (int) $maxLimit : null;

            $this->displaySuccess("Starting queue worker on queue: {$queue}");
            $configInfo = "Configuration: sleep={$sleep}s, memory={$maxMemory}MB, timeout={$maxTime}s";

            if (!empty($maxLimit)) {
                $configInfo .= ", limit={$maxLimit} jobs";
            } else {
                $configInfo .= ", limit=unlimited";
            }

            $this->info($configInfo);

            try {
                $this->worker->setOnJobProcessing(function ($job) {
                    $jobClass = get_class($job);
                    $jobId = $job->getJobId() ?? 'N/A';
                    $this->info("✔ Processing job [{$jobClass}] (ID: {$jobId})");
                });

                $this->worker->setOnJobProcessed(function ($job) {
                    $jobClass = get_class($job);
                    $jobId = $job->getJobId() ?? 'N/A';
                    $this->info("✔ Processed job [{$jobClass}] (ID: {$jobId})");

                    // Flush system output buffer
                    flush();
                });

                $this->worker->daemon($queue, [
                    'sleep' => $sleep,
                    'maxMemory' => $maxMemory,
                    'maxExecutionTime' => $maxTime,
                    'maxJobs' => $maxLimit,
                ]);

                return Command::SUCCESS;
            } catch (\Throwable $e) {
                $this->error("Queue worker failed: " . $e->getMessage());
                $this->error($e->getTraceAsString());
                return Command::FAILURE;
            }
        }, 'Queue worker stopped gracefully.');
    }
}
