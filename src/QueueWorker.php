<?php

namespace Doppar\Queue;

use Doppar\Queue\Support\ReservedJob;
use Doppar\Queue\Exceptions\MaxAttemptsExceededException;
use Doppar\Queue\Exceptions\JobTimeoutException;
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
    protected $maxExecutionTime = 0;

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
     * The maximum number of jobs to process.
     *
     * @var int|null
     */
    protected $maxJobs = null;

    /**
     * The number of jobs processed.
     *
     * @var int
     */
    protected $jobsProcessed = 0;

    /**
     * Callback to be executed **before** a job is processed.
     *
     * @var callable|null
     */
    protected $onJobProcessing;

    /**
     * Callback to be executed **after** a job has been successfully processed.
     *
     *
     * @var callable|null
     */
    protected $onJobProcessed;

    /**
     * Flag to indicate if we're inside a forked timeout context.
     *
     * @var bool
     */
    protected $insideTimeoutContext = false;

    /**
     * The queue connection this worker consumes, or null for the default.
     *
     * @var string|null
     */
    protected ?string $connection = null;

    /**
     * The job currently being executed, kept so its lease can be renewed.
     *
     * @var ReservedJob|null
     */
    protected ?ReservedJob $reservation = null;

    /**
     * Unix time the lease of the current job was last renewed.
     *
     * @var int
     */
    protected int $leaseRenewedAt = 0;

    /**
     * Seconds between lease renewals while a job runs in a timeout child process.
     *
     * @var int
     */
    protected int $leaseRenewEvery = 20;

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
     * Set the callback to be executed **before** a job is processed.
     *
     * @param callable $callback
     * @return void
     */
    public function setOnJobProcessing(callable $callback): void
    {
        $this->onJobProcessing = $callback;
    }

    /**
     * Set the callback to be executed **after** a job has been processed.
     *
     * @param callable $callback
     * @return void
     */
    public function setOnJobProcessed(callable $callback): void
    {
        $this->onJobProcessed = $callback;
    }

    /**
     * Claim and process at most one job, without sleeping when the queue is empty.
     *
     * @param string|array<int, string> $queue A name, a comma separated list, or an array, tried in order
     * @return bool
     */
    public function runNextJob(string|array $queue = 'default'): bool
    {
        $queueJob = $this->manager->pop($queue, $this->connection);

        if ($queueJob === null) {
            return false;
        }

        $this->processJob($queueJob);
        $this->jobsProcessed++;

        return true;
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

            // Check if max jobs limit reached
            if ($this->maxJobsReached()) {
                $this->stop(0, "Maximum job limit of {$this->maxJobs} reached");
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
            $queueJob = $this->manager->pop($queue, $this->connection);

            if ($queueJob === null) {
                $this->sleep($this->sleep);
                return;
            }

            $this->processJob($queueJob);
            $this->jobsProcessed++;
        } catch (\Throwable $e) {
            $this->handleWorkerException($e);
            $this->sleep($this->sleep);
        }
    }

    /**
     * Process a single job.
     *
     * @param ReservedJob $queueJob
     * @return void
     */
    protected function processJob(ReservedJob $queueJob): void
    {
        $job = null;

        try {
            // Unserialize the job
            $job = $this->manager->unserializeJob($queueJob->payload);
            $job->attempts = $queueJob->attempts;

            // A job whose lease expired mid-run comes back with its attempts
            // already spent; do not run it again.
            $maxAttempts = max(1, $job->tries());

            if ($queueJob->attempts > $maxAttempts) {
                throw new MaxAttemptsExceededException(
                    "Job has been attempted too many times ({$queueJob->attempts} of {$maxAttempts})."
                );
            }

            if (is_callable($this->onJobProcessing)) {
                ($this->onJobProcessing)($job);
            }

            $this->reservation = $queueJob;
            $this->leaseRenewedAt = time();

            // Execute the job with timeout
            $this->executeJobWithTimeout($job);
        } catch (\Throwable $e) {
            $this->reservation = null;
            $this->handleJobException($queueJob, $job, $e);

            return;
        }

        $this->reservation = null;

        // The job ran. If it cannot be removed, its lease will expire and it will
        // run again (at-least-once); that is not a job failure, and the follow-up
        // work below is left to the run that finally removes it, so a chain is
        // never advanced twice.
        try {
            if (!$this->manager->delete($queueJob, $this->connection)) {
                $this->logError("Job {$job->getJobId()} finished but its lease was lost; another worker may run it again");

                return;
            }
        } catch (\Throwable $e) {
            $this->logError("Job {$job->getJobId()} finished but could not be removed from the queue: " . $e->getMessage());

            return;
        }

        try {
            // Dispatch next job in chain if this job is chained
            if ($job->isChained()) {
                $job->dispatchNextChainJob();
            }

            // Trigger onJobProcessed callback
            if (is_callable($this->onJobProcessed)) {
                ($this->onJobProcessed)($job);
            }
        } catch (\Throwable $e) {
            $this->logError("Post-processing of job {$job->getJobId()} failed: " . $e->getMessage());
        }
    }

    /**
     * Execute a job with timeout protection using process forking.
     *
     * @param JobInterface $job
     * @return void
     * @throws \Throwable
     */
    protected function executeJobWithTimeout(JobInterface $job): void
    {
        $timeout = $job->getTimeout();

        // No timeout or PCNTL not available
        if ($timeout === null || !extension_loaded('pcntl') || !function_exists('pcntl_fork')) {
            $this->executeJob($job);
            return;
        }

        // Clean up database connections before forking
        db()->cleanupAllConnections();

        $this->insideTimeoutContext = true;
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->insideTimeoutContext = false;
            $this->logError("Failed to fork process, executing without timeout");
            $this->executeJob($job);
            return;
        }

        if ($pid === 0) {
            // CHILD PROCESS
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);

            try {
                // Create new database connection in child
                db()->cleanupAllConnections();
                $this->executeJob($job);
                exit(0);
            } catch (\Throwable $e) {
                error("Job exception: " . $e->getMessage());
                exit(1);
            }
        }

        // PARENT PROCESS
        $startTime = time();
        $timedOut = false;

        while (true) {
            $status = null;
            $result = pcntl_waitpid($pid, $status, WNOHANG);

            if ($result === $pid) {
                $this->insideTimeoutContext = false;

                // Clean up parent's connections too
                db()->cleanupAllConnections();

                if (pcntl_wifexited($status)) {
                    $exitCode = pcntl_wexitstatus($status);

                    if ($exitCode === 0) {
                        return;
                    }

                    throw new \RuntimeException("Job process exited with code {$exitCode}");
                }

                if (pcntl_wifsignaled($status)) {
                    $signal = pcntl_wtermsig($status);

                    if ($timedOut) {
                        throw new JobTimeoutException(
                            "Job exceeded maximum execution time of {$timeout} seconds"
                        );
                    }

                    throw new \RuntimeException("Job process was terminated by signal {$signal}");
                }

                return;
            }

            $this->renewLease();

            // Check timeout
            if ((time() - $startTime) >= $timeout) {
                $timedOut = true;
                $this->logError("Job timeout exceeded ({$timeout}s), killing process {$pid}");

                // Kill the process
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);

                // Clean up connections
                $this->insideTimeoutContext = false;
                db()->cleanupAllConnections();

                throw new JobTimeoutException(
                    "Job exceeded maximum execution time of {$timeout} seconds"
                );
            }

            // 100ms
            usleep(100000);
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
        $job->handle();
    }

    /**
     * Handle an exception that occurred while processing a job.
     *
     * @param ReservedJob $queueJob
     * @param JobInterface|null $job
     * @param \Throwable $exception
     * @return void
     */
    protected function handleJobException(ReservedJob $queueJob, ?JobInterface $job, \Throwable $exception): void
    {
        try {
            $this->logError("Job failed: " . $exception->getMessage());

            if ($job === null) {
                // Could not unserialize job, mark as failed immediately
                $this->manager->markAsFailed($queueJob, $exception, $this->connection);
                return;
            }

            // Handle chain failure - chain stops here
            if ($job->isChained()) {
                $job->handleChainFailure($exception);
            }

            // Check if job should be retried
            if ($queueJob->attempts < $job->tries()) {
                // Release the job back to the queue with delay
                $delay = $this->retryDelay($job, $queueJob->attempts);
                $released = $this->manager->release($queueJob, $delay, $this->connection);

                if ($released) {
                    $this->logInfo("Job {$job->getJobId()} released back to queue (attempt {$queueJob->attempts}/{$job->tries()})");
                } else {
                    $this->logError("Failed to release job {$job->getJobId()} back to queue");
                }
            } else {
                // Max attempts reached, mark as failed
                $this->manager->markAsFailed($queueJob, $exception, $this->connection);

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
     * Work out how long to wait before retrying a job.
     *
     * @param JobInterface $job
     * @param int $attempts
     * @return int Seconds
     */
    protected function retryDelay(JobInterface $job, int $attempts): int
    {
        $backoff = method_exists($job, 'backoff') ? $job->backoff() : null;

        if (is_array($backoff) && $backoff !== []) {
            $backoff = array_values($backoff);

            return max(0, (int) $backoff[min(max($attempts, 1), count($backoff)) - 1]);
        }

        if (is_int($backoff)) {
            return max(0, $backoff);
        }

        return max(0, $job->retryAfter());
    }

    /**
     * Keep the lease of the running job alive.
     *
     * @return void
     */
    protected function renewLease(): void
    {
        if ($this->reservation === null || (time() - $this->leaseRenewedAt) < $this->leaseRenewEvery) {
            return;
        }

        $this->leaseRenewedAt = time();

        try {
            $this->manager->extendLease($this->reservation, $this->leaseRenewEvery * 3, $this->connection);
        } catch (\Throwable $e) {
            $this->logError("Could not renew job lease: " . $e->getMessage());
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
     * Determine if the maximum number of jobs has been reached.
     *
     * @return bool
     */
    protected function maxJobsReached(): bool
    {
        return !empty($this->maxJobs) && $this->jobsProcessed >= $this->maxJobs;
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
        if ($this->maxExecutionTime <= 0) {
            return false;
        }

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
        if (!extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);

        // SIGTERM handler - only trigger if not in timeout context
        pcntl_signal(SIGTERM, function () {
            if (!$this->insideTimeoutContext) {
                $this->stop(0, 'Received SIGTERM signal');
            }
        });

        // SIGINT handler - only trigger if not in timeout context
        pcntl_signal(SIGINT, function () {
            if (!$this->insideTimeoutContext) {
                $this->stop(0, 'Received SIGINT signal');
            }
        });
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

        $this->maxJobs = $options['maxJobs'] ?? null;

        if (isset($options['connection'])) {
            $this->connection = (string) $options['connection'];
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

    /**
     * Set the maximum number of jobs to process.
     *
     * @param int|null $maxJobs
     * @return void
     */
    public function setMaxJobs(?int $maxJobs): void
    {
        $this->maxJobs = $maxJobs;
    }

    /**
     * Set the queue connection to consume.
     *
     * @param string|null $connection
     * @return void
     */
    public function setConnection(?string $connection): void
    {
        $this->connection = $connection;
    }

    /**
     * Set how often, in seconds, the lease of a running timeout job is renewed.
     *
     * @param int $seconds
     * @return void
     */
    public function setLeaseRenewInterval(int $seconds): void
    {
        $this->leaseRenewEvery = max(1, $seconds);
    }

    /**
     * Get the number of jobs processed.
     *
     * @return int
     */
    public function getJobsProcessed(): int
    {
        return $this->jobsProcessed;
    }
}
