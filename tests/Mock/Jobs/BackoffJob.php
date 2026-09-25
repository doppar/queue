<?php

namespace Doppar\Queue\Tests\Mock\Jobs;

use Doppar\Queue\Job;

/**
 * Always fails, with a configurable backoff.
 */
class BackoffJob extends Job
{
    public $tries = 5;

    public $retryAfter = 999;

    public function __construct(int|array|null $backoff = [10, 60, 300])
    {
        $this->backoff = $backoff;
    }

    public function handle(): void
    {
        throw new \RuntimeException('always fails');
    }
}
