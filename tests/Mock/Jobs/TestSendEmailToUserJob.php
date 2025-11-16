<?php

namespace Doppar\Queue\Tests\Mock\Jobs;

use Doppar\Queue\Tests\Mock\Models\MockUser;
use Doppar\Queue\Job;
use Doppar\Queue\Dispatchable;

class TestSendEmailToUserJob extends Job
{
    use Dispatchable;

    protected $user;

    public function __construct(MockUser $user)
    {
        $this->user = $user;
    }

    public function handle(): void
    {
        // Simulate sending email
        if ($this->user) {
            // Email sent successfully
        }
    }
}
