<?php

namespace Doppar\Queue\Tests\Mock\Jobs;

use Doppar\Queue\Attributes\Queueable;
use Doppar\Queue\Job;

#[Queueable(tries: 4, onQueue: 'reports', priority: 25, onConnection: 'memory', backoff: [5, 15])]
class QueueableAttributeJob extends Job
{
    public function handle(): void
    {
    }
}
