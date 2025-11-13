<?php

namespace Doppar\Queue\Contracts;

interface JobInterface
{
    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void;

    /**
     * Get the number of times the job may be attempted.
     *
     * @return int
     */
    public function tries(): int;

    /**
     * Get the number of seconds before a job should be made available again after failure.
     *
     * @return int
     */
    public function retryAfter(): int;

    /**
     * Handle a job failure.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void;

    /**
     * Get the queue name this job should be pushed to.
     *
     * @return string
     */
    public function queue(): string;

    /**
     * Get the job delay in seconds.
     *
     * @return int
     */
    public function delay(): int;

    /**
     * Get the job identifier.
     *
     * @return string|null
     */
    public function getJobId(): ?string;

    /**
     * Set the job identifier.
     *
     * @param string $id
     * @return void
     */
    public function setJobId(string $id): void;
}
