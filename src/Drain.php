<?php

namespace Doppar\Queue;

use Doppar\Queue\Contracts\JobInterface;

class Drain
{
    /**
     * The jobs in the chain.
     *
     * @var array<JobInterface>
     */
    protected array $jobs = [];

    /**
     * The queue name for the chain.
     *
     * @var string
     */
    protected string $queueName = 'default';

    /**
     * The delay before starting the chain.
     *
     * @var int
     */
    protected int $delay = 0;

    /**
     * Callback to run when all jobs complete.
     *
     * @var callable|null
     */
    protected $onComplete = null;

    /**
     * Callback to run when any job fails.
     *
     * @var callable|null
     */
    protected $onFailure = null;

    /**
     * Chain identifier.
     *
     * @var string
     */
    protected string $chainId;

    /**
     * Create a new job chain.
     *
     * @param array<JobInterface> $jobs
     */
    public function __construct(array $jobs = [])
    {
        $this->jobs = array_values($jobs);
        $this->chainId = $this->generateChainId();
    }

    /**
     * Create a new job chain.
     *
     * @param array<JobInterface> $jobs
     * @return static
     */
    public static function conduct(array $jobs): static
    {
        return new static($jobs);
    }

    /**
     * Add a job to the chain.
     *
     * @param JobInterface $job
     * @return $this
     */
    public function add(JobInterface $job): self
    {
        $this->jobs[] = $job;

        return $this;
    }

    /**
     * Set the queue for all jobs in the chain.
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
     * Set the delay before starting the chain.
     *
     * @param int $seconds
     * @return $this
     */
    public function delay(int $seconds): self
    {
        $this->delay = $seconds;

        return $this;
    }

    /**
     * Set callback to run when all jobs complete.
     *
     * @param callable $callback
     * @return $this
     */
    public function then(callable $callback): self
    {
        $this->onComplete = $callback;

        return $this;
    }

    /**
     * Set callback to run when any job fails.
     *
     * @param callable $callback
     * @return $this
     */
    public function catch(callable $callback): self
    {
        $this->onFailure = $callback;

        return $this;
    }

    /**
     * Dispatch the job chain.
     *
     * Only the FIRST job is pushed to queue.
     * Each job will push the next one after successful completion.
     *
     * @return string
     */
    public function dispatch(): string
    {
        if (empty($this->jobs)) {
            throw new \InvalidArgumentException('Cannot dispatch an empty job chain');
        }

        // Prepare the first job with chain context
        $firstJob = $this->jobs[0];

        // Attach chain metadata to first job
        $firstJob->chainId = $this->chainId;
        $firstJob->chainJobs = $this->jobs;
        $firstJob->chainIndex = 0;
        $firstJob->chainOnComplete = $this->onComplete;
        $firstJob->chainOnFailure = $this->onFailure;

        // Set queue and delay
        $firstJob->onQueue($this->queueName);
        $firstJob->delayFor($this->delay);

        // Push ONLY the first job to queue
        $firstJob->forceQueue();

        return $this->chainId;
    }

    /**
     * Execute the chain synchronously.
     *
     * @return void
     */
    public function dispatchSync(): void
    {
        foreach ($this->jobs as $index => $job) {
            try {
                $job->handle();
            } catch (\Throwable $e) {
                if ($this->onFailure) {
                    ($this->onFailure)($job, $e, $index);
                }
                throw $e;
            }
        }

        if ($this->onComplete) {
            ($this->onComplete)();
        }
    }

    /**
     * Get the jobs in the chain.
     *
     * @return array<JobInterface>
     */
    public function getJobs(): array
    {
        return $this->jobs;
    }

    /**
     * Generate a unique chain ID.
     *
     * @return string
     */
    protected function generateChainId(): string
    {
        return 'chain_' . uniqid('', true);
    }
}
