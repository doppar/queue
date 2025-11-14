<?php

namespace Doppar\Queue\Tests\Mock\Jobs;

use Doppar\Queue\Job;

class TestEmailJob extends Job
{
    public $tries = 3;
    public $retryAfter = 60;
    public $to;
    public $subject;

    public function __construct(string $to, string $subject)
    {
        $this->to = $to;
        $this->subject = $subject;
    }

    public function handle(): void
    {
        // Simulate sending email
        if (empty($this->to) || empty($this->subject)) {
            throw new \RuntimeException('Invalid email data');
        }
    }
}
