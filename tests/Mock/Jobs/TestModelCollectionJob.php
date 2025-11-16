<?php

namespace Doppar\Queue\Tests\Mock\Jobs;

use Phaseolies\Support\Collection;
use Doppar\Queue\Tests\Mock\Models\MockUser;
use Doppar\Queue\Job;
use Doppar\Queue\Dispatchable;

class TestModelCollectionJob extends Job
{
    use Dispatchable;

    protected $users;
    public $processedCount = 0;

    public function __construct(Collection $users)
    {
        $this->users = $users;
    }

    public function handle(): void
    {
        foreach ($this->users as $user) {
            if ($user !== null) {
                $this->processedCount++;
                // Process user
            }
        }
    }
}
