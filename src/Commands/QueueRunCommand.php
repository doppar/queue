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
    protected $name = 'queue:run {--queue=default} {--sleep=3} {--memory=128} {--timeout=3600}';

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

            $this->info("Starting queue worker on queue: {$queue}");
            $this->info("Configuration: sleep={$sleep}s, memory={$maxMemory}MB, timeout={$maxTime}s");

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
