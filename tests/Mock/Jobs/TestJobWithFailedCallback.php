<?php

namespace Doppar\Queue\Tests\Mock\Jobs;

use Doppar\Queue\Job;

class TestJobWithFailedCallback extends Job
{
    public $tries = 1;
    public $failedCalled = false;

    public function handle(): void
    {
        throw new \RuntimeException('Intentional failure');
    }

    public function failed(\Throwable $exception): void
    {
        $this->failedCalled = true;
    }
}
