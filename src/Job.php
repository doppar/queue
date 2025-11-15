<?php

namespace Doppar\Queue;

use Doppar\Queue\Facades\Queue;
use Doppar\Queue\Contracts\JobInterface;
use Doppar\Queue\Attributes\Queueable;

abstract class Job implements JobInterface
{
    use InteractsWithQueueableAttributes;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $retryAfter = 60;

    /**
     * The name of the queue the job should be sent to.
     *
     * @var string
     */
    public $queueName = 'default';

    /**
     * The number of seconds before the job should be made available.
     *
     * @var int
     */
    public $jobDelay = 0;

    /**
     * The job identifier.
     *
     * @var string|null
     */
    protected $jobId;

    /**
     * The number of times this job has been attempted.
     *
     * @var int
     */
    public $attempts = 0;

    /**
     * Get the number of times the job may be attempted.
     *
     * @return int
     */
    public function tries(): int
    {
        return $this->tries;
    }

    /**
     * Get the number of seconds before retrying.
     *
     * @return int
     */
    public function retryAfter(): int
    {
        return $this->retryAfter;
    }

    /**
     * Handle a job failure.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        // Override in child classes if needed
    }

    /**
     * Get the queue name.
     *
     * @return string
     */
    public function queue(): string
    {
        return $this->queueName;
    }

    /**
     * Get the job delay.
     *
     * @return int
     */
    public function delay(): int
    {
        return $this->jobDelay;
    }

    /**
     * Get the job identifier.
     *
     * @return string|null
     */
    public function getJobId(): ?string
    {
        return $this->jobId;
    }

    /**
     * Set the job identifier.
     *
     * @param string $id
     * @return void
     */
    public function setJobId(string $id): void
    {
        $this->jobId = $id;
    }

    /**
     * Set the queue name.
     *
     * @param string $queue
     * @return $this
     */
    public function onQueue(string $queue): self
    {
        $this->queueName = $queue;

        return $this;
    }

    /**
     * Set the job delay.
     *
     * @param int $delay
     * @return $this
     */
    public function delayFor(int $delay): self
    {
        $this->jobDelay = $delay;

        return $this;
    }

    /**
     * Check if the job should be queued based on the Queueable attribute.
     *
     * @return bool
     */
    public function shouldQueue(): bool
    {
        $reflection = new \ReflectionClass($this);
        $attributes = $reflection->getAttributes(Queueable::class);

        return !empty($attributes);
    }

    /**
     * Dispatch the job to the queue.
     *
     * @return string|null
     */
    public function dispatch(): ?string
    {
        $this->applyQueueableAttributes();

        if ($this->shouldQueue()) {
            return Queue::push($this);
        }

        $this->handle();

        return null;
    }

    /**
     * Dispatch the job to the queue after a delay.
     *
     * @param int $delay Delay in seconds
     * @return string Job ID
     */
    public function dispatchAfter(int $delay): string
    {
        $this->delayFor($delay);

        $this->applyQueueableAttributes();

        return $this->dispatch();
    }

    /**
     * Dispatch the job to a specific queue.
     *
     * @param string $queue
     * @return string Job ID
     */
    public function dispatchOn(string $queue): string
    {
        $this->onQueue($queue);

        $this->applyQueueableAttributes();

        return $this->dispatch();
    }

    /**
     * Dispatch the job.
     *
     * @param mixed ...$args
     * @return string Job ID
     */
    public static function dispatchNow(...$args): string
    {
        $job = new static(...$args);

        return $job->dispatch();
    }

    /**
     * Dispatch the job synchronously
     *
     * @param mixed ...$args
     * @return void
     */
    public static function dispatchSync(...$args): void
    {
        $job = new static(...$args);

        $job->handle();
    }

    /**
     * Force the job to be queued even without Queueable attribute.
     *
     * @return string Job ID
     */
    public function forceQueue(): string
    {
        $this->applyQueueableAttributes();

        return Queue::push($this);
    }
}
