<?php

namespace Doppar\Queue\Tests\Mock\Jobs;

use Doppar\Queue\Job;

class TestCounterJob extends Job
{
    public $counter = 0;

    public function handle(): void
    {
        $this->counter++;
    }
}
