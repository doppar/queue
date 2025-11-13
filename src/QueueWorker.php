<?php

namespace Doppar\Queue;

use Doppar\Queue\Models\QueueJob;
use Doppar\Queue\Contracts\JobInterface;

class QueueWorker
{
    /**
     * The queue manager instance.
     *
     * @var QueueManager
     */
    protected $manager;

    /**
     * Indicates if the worker should stop processing.
     *
     * @var bool
     */
    protected $shouldQuit = false;

    /**
     * The maximum number of seconds a worker may run.
     *
     * @var int
     */
    protected $maxExecutionTime = 3600;

    /**
     * The number of seconds to wait before polling the queue.
     *
     * @var int
     */
    protected $sleep = 3;

    /**
     * The maximum amount of RAM the worker may consume.
     *
     * @var int (in megabytes)
     */
    protected $maxMemory = 128;

    /**
     * Create a new queue worker.
     *
     * @param QueueManager $manager
     */
    public function __construct(QueueManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * Run the worker daemon.
     *
     * @param string $queue
     * @param array $options
     * @return void
     */
    public function daemon(string $queue = 'default', array $options = []): void
    {
        $this->configureOptions($options);
        $this->registerSignalHandlers();

        $startTime = time();

        while (true) {
            // Check if we should quit
            if ($this->shouldQuit()) {
                break;
            }

            // Check memory usage
            if ($this->memoryExceeded()) {
                $this->stop(12, 'Memory limit exceeded');
                break;
            }

            // Check execution time
            if ($this->timeExceeded($startTime)) {
                $this->stop(13, 'Execution time limit exceeded');
                break;
            }

            // Process the next job
            $this->processNextJob($queue);
        }
    }

    /**
     * Process the next job on the queue.
     *
     * @param string $queue
     * @return void
     */
    protected function processNextJob(string $queue): void
    {
        try {
            $queueJob = $this->manager->pop($queue);

            if ($queueJob === null) {
                $this->sleep($this->sleep);
                return;
            }

            $this->processJob($queueJob);
        } catch (\Throwable $e) {
            $this->handleWorkerException($e);
            $this->sleep($this->sleep);
        }
    }

    /**
     * Process a single job.
     *
     * @param QueueJob $queueJob
     * @return void
     */
    protected function processJob(QueueJob $queueJob): void
    {
        try {
            // Unserialize the job
            $job = $this->manager->unserializeJob($queueJob->payload);
            $job->attempts = $queueJob->attempts;

            // Execute the job
            $this->executeJob($job);

            // Delete the job from queue if successful
            $this->manager->delete($queueJob);

            $this->logInfo("Job {$job->getJobId()} processed successfully");
        } catch (\Throwable $e) {
            $this->handleJobException($queueJob, $job ?? null, $e);
        }
    }

    /**
     * Execute a job.
     *
     * @param JobInterface $job
     * @return void
     * @throws \Throwable
     */
    protected function executeJob(JobInterface $job): void
    {
        try {
            $job->handle();
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    /**
     * Handle an exception that occurred while processing a job.
     *
     * @param QueueJob $queueJob
     * @param JobInterface|null $job
     * @param \Throwable $exception
     * @return void
     */
    protected function handleJobException(QueueJob $queueJob, ?JobInterface $job, \Throwable $exception): void
    {
        try {
            $this->logError("Job failed: " . $exception->getMessage());

            if ($job === null) {
                // Could not unserialize job, mark as failed immediately
                $this->manager->markAsFailed($queueJob, $exception);
                return;
            }

            // Check if job should be retried
            if ($queueJob->attempts < $job->tries()) {
                // Release the job back to the queue with delay
                $delay = $job->retryAfter();
                $this->manager->release($queueJob, $delay);
                $this->logInfo("Job {$job->getJobId()} released back to queue (attempt {$queueJob->attempts}/{$job->tries()})");
            } else {
                // Max attempts reached, mark as failed
                $this->manager->markAsFailed($queueJob, $exception);

                // Call the failed method on the job
                try {
                    $job->failed($exception);
                } catch (\Throwable $e) {
                    $this->logError("Failed callback error: " . $e->getMessage());
                }

                $this->logError("Job {$job->getJobId()} marked as failed after {$queueJob->attempts} attempts");
            }
        } catch (\Throwable $e) {
            $this->logError("Error handling job exception: " . $e->getMessage());
        }
    }

    /**
     * Handle an exception that occurred while the worker was running.
     *
     * @param \Throwable $exception
     * @return void
     */
    protected function handleWorkerException(\Throwable $exception): void
    {
        $this->logError("Worker exception: " . $exception->getMessage());
    }

    /**
     * Determine if the worker should quit.
     *
     * @return bool
     */
    protected function shouldQuit(): bool
    {
        return $this->shouldQuit;
    }

    /**
     * Stop the worker.
     *
     * @param int $status
     * @param string $message
     * @return void
     */
    public function stop(int $status = 0, string $message = ''): void
    {
        $this->shouldQuit = true;

        if ($message) {
            $this->logInfo($message);
        }
    }

    /**
     * Determine if the memory limit has been exceeded.
     *
     * @return bool
     */
    protected function memoryExceeded(): bool
    {
        return (memory_get_usage(true) / 1024 / 1024) >= $this->maxMemory;
    }

    /**
     * Determine if the time limit has been exceeded.
     *
     * @param int $startTime
     * @return bool
     */
    protected function timeExceeded(int $startTime): bool
    {
        return (time() - $startTime) >= $this->maxExecutionTime;
    }

    /**
     * Sleep for the given number of seconds.
     *
     * @param int $seconds
     * @return void
     */
    protected function sleep(int $seconds): void
    {
        if ($seconds < 1) {
            usleep($seconds * 1000000);
        } else {
            sleep($seconds);
        }
    }

    /**
     * Register signal handlers for graceful shutdown.
     *
     * @return void
     */
    protected function registerSignalHandlers(): void
    {
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);

            pcntl_signal(SIGTERM, function () {
                $this->stop(0, 'Received SIGTERM signal');
            });

            pcntl_signal(SIGINT, function () {
                $this->stop(0, 'Received SIGINT signal');
            });
        }
    }

    /**
     * Configure worker options.
     *
     * @param array $options
     * @return void
     */
    protected function configureOptions(array $options): void
    {
        if (isset($options['sleep'])) {
            $this->sleep = (int) $options['sleep'];
        }

        if (isset($options['maxMemory'])) {
            $this->maxMemory = (int) $options['maxMemory'];
        }

        if (isset($options['maxExecutionTime'])) {
            $this->maxExecutionTime = (int) $options['maxExecutionTime'];
        }
    }

    /**
     * Log an informational message.
     *
     * @param string $message
     * @return void
     */
    protected function logInfo(string $message): void
    {
        echo "[" . date('Y-m-d H:i:s') . "] INFO: $message\n";
    }

    /**
     * Log an error message.
     *
     * @param string $message
     * @return void
     */
    protected function logError(string $message): void
    {
        echo "[" . date('Y-m-d H:i:s') . "] ERROR: $message\n";
    }

    /**
     * Set the sleep duration.
     *
     * @param int $seconds
     * @return void
     */
    public function setSleep(int $seconds): void
    {
        $this->sleep = $seconds;
    }

    /**
     * Set the maximum memory.
     *
     * @param int $megabytes
     * @return void
     */
    public function setMaxMemory(int $megabytes): void
    {
        $this->maxMemory = $megabytes;
    }

    /**
     * Set the maximum execution time.
     *
     * @param int $seconds
     * @return void
     */
    public function setMaxExecutionTime(int $seconds): void
    {
        $this->maxExecutionTime = $seconds;
    }
}
