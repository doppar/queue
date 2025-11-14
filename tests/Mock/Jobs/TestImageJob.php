<?php

namespace Doppar\Queue\Tests\Mock\Jobs;

use Doppar\Queue\Job;

class TestImageJob extends Job
{
    public $tries = 3;
    public $retryAfter = 120;
    public $imagePath;

    public function __construct(string $imagePath)
    {
        $this->imagePath = $imagePath;
    }

    public function handle(): void
    {
        if (empty($this->imagePath)) {
            throw new \RuntimeException('Invalid image path');
        }
    }
}
