<?php

namespace Doppar\Queue;

use Doppar\Queue\Models\FailedJob;
use Doppar\Queue\Models\QueueJob;
use Doppar\Queue\Exceptions\QueueException;
use Doppar\Queue\Contracts\JobInterface;

class QueueManager
{
    /**
     * The default queue name.
     *
     * @var string
     */
    protected $defaultQueue = 'default';

    /**
     * Push a job onto the queue.
     *
     * @param JobInterface $job
     * @return string Job ID
     * @throws QueueException
     */
    public function push(JobInterface $job): string
    {
        try {
            $jobId = $this->generateJobId();
            $job->setJobId($jobId);

            $payload = $this->createPayload($job);
            $availableAt = time() + $job->delay();

            QueueJob::create([
                'queue' => $job->queue(),
                'payload' => $payload,
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => $availableAt,
                'created_at' => time(),
            ]);

            return $jobId;
        } catch (\Throwable $e) {
            throw new QueueException("Failed to push job to queue: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Pop the next job off the queue.
     *
     * @param string $queue
     * @return QueueJob|null
     */
    public function pop(string $queue = 'default'): ?QueueJob
    {
        try {
            $job = QueueJob::available($queue)->first();

            if ($job) {
                $job->reserve();
            }

            return $job;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Delete a job from the queue.
     *
     * @param QueueJob $queueJob
     * @return bool
     */
    public function delete(QueueJob $queueJob): bool
    {
        try {
            return $queueJob->deleteJob();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Release a job back to the queue.
     *
     * @param QueueJob $queueJob
     * @param int $delay
     * @return bool
     */
    public function release(QueueJob $queueJob, int $delay = 0): bool
    {
        try {
            return $queueJob->release($delay);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Move a job to the failed jobs table.
     *
     * @param QueueJob $queueJob
     * @param \Throwable $exception
     * @return void
     */
    public function markAsFailed(QueueJob $queueJob, \Throwable $exception): void
    {
        try {
            FailedJob::create([
                'connection' => 'database',
                'queue' => $queueJob->queue,
                'payload' => $queueJob->payload,
                'exception' => $this->formatException($exception),
                'failed_at' => time(),
            ]);

            $this->delete($queueJob);
        } catch (\Throwable $e) {
            error("Failed to mark job as failed: " . $e->getMessage());
        }
    }

    /**
     * Get the count of jobs in a queue.
     *
     * @param string $queue
     * @return int
     */
    public function size(string $queue = 'default'): int
    {
        return QueueJob::where('queue', $queue)
            ->whereNull('reserved_at')
            ->count();
    }

    /**
     * Clear all jobs from a queue.
     *
     * @param string $queue
     * @return int Number of jobs deleted
     */
    public function clear(string $queue = 'default'): int
    {
        return QueueJob::where('queue', $queue)->delete();
    }

    /**
     * Create a payload string from the given job.
     *
     * @param JobInterface $job
     * @return string
     */
    protected function createPayload(JobInterface $job): string
    {
        return serialize([
            'job' => $job,
            'data' => [
                'jobId' => $job->getJobId(),
                'queue' => $job->queue(),
                'tries' => $job->tries(),
                'retryAfter' => $job->retryAfter(),
            ],
        ]);
    }

    /**
     * Unserialize the job from payload.
     *
     * @param string $payload
     * @return JobInterface
     * @throws QueueException
     */
    public function unserializeJob(string $payload): JobInterface
    {
        try {
            $data = unserialize($payload);
            return $data['job'];
        } catch (\Throwable $e) {
            throw new QueueException("Failed to unserialize job: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Generate a unique job ID.
     *
     * @return string
     */
    protected function generateJobId(): string
    {
        return uniqid('job_', true);
    }

    /**
     * Format exception for storage.
     *
     * @param \Throwable $exception
     * @return string
     */
    protected function formatException(\Throwable $exception): string
    {
        return sprintf(
            "%s: %s in %s:%d\nStack trace:\n%s",
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        );
    }

    /**
     * Set the default queue name.
     *
     * @param string $queue
     * @return void
     */
    public function setDefaultQueue(string $queue): void
    {
        $this->defaultQueue = $queue;
    }

    /**
     * Get the default queue name.
     *
     * @return string
     */
    public function getDefaultQueue(): string
    {
        return $this->defaultQueue;
    }
}
