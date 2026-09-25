<?php

namespace Doppar\Queue\Tests\Mock\Jobs;

use Doppar\Queue\Job;

/**
 * Takes a while to finish, and must run in the worker's timeout child process.
 */
class SleepJob extends Job
{
    public function __construct(public int $seconds = 5)
    {
        $this->timeout = 30;
    }

    public function handle(): void
    {
        sleep($this->seconds);
    }
}
