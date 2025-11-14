<?php

namespace Doppar\Queue\Tests\Mock\Jobs;

use Doppar\Queue\Job;

class TestComplexDataJob extends Job
{
    public $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function handle(): void
    {
        if (empty($this->data)) {
            throw new \RuntimeException('No data provided');
        }
    }
}
