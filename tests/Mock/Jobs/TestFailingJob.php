<?php

namespace Doppar\Queue\Tests\Mock\Jobs;

use Doppar\Queue\Job;

class TestFailingJob extends Job
{
    public $tries = 3;
    public $retryAfter = 60;

    public function handle(): void
    {
        throw new \RuntimeException('Test failure');
    }
}