<?php

namespace Doppar\Queue\Tests\Mock\Jobs;

use Phaseolies\Support\Collection;
use Doppar\Queue\Tests\Mock\Models\MockUser;
use Doppar\Queue\Job;
use Doppar\Queue\Dispatchable;

class TestDeletedModelJob extends Job
{
    use Dispatchable;

    protected $user;
    public $wasUserNull = false;

    public function __construct(MockUser $user)
    {
        $this->user = $user;
    }

    public function handle(): void
    {
        if ($this->user === null) {
            $this->wasUserNull = true;
            // Handle gracefully
            return;
        }

        // Process user
    }
}