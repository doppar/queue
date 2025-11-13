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
            $queue = $this->getOption('queue', 'default');
            $sleep = (int) $this->getOption('sleep', 3);
            $maxMemory = (int) $this->getOption('memory', 128);
            $maxTime = (int) $this->getOption('timeout', 3600);

            $this->info("Starting queue worker on queue: {$queue}");
            $this->info("Configuration: sleep={$sleep}s, memory={$maxMemory}MB, timeout={$maxTime}s");

            try {
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

    /**
     * Get an option value.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function getOption(string $key, $default = null)
    {
        // Implementation depends on your console command system
        // This is a placeholder - adapt to your actual implementation
        global $argv;

        foreach ($argv as $i => $arg) {
            if (strpos($arg, "--{$key}=") === 0) {
                return substr($arg, strlen("--{$key}="));
            }
            if ($arg === "--{$key}" && isset($argv[$i + 1])) {
                return $argv[$i + 1];
            }
        }

        return $default;
    }
}
