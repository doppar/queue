<?php

namespace Doppar\Queue\Tests\Mock\Jobs;

use Doppar\Queue\Tests\Mock\Models\MockUser;
use Doppar\Queue\Job;
use Doppar\Queue\Dispatchable;

class TestMixedDataJob extends Job
{
    use Dispatchable;

    protected $user;
    protected $title;
    protected $options;
    public $wasSuccessful = false;

    public function __construct(MockUser $user, string $title, array $options)
    {
        $this->user = $user;
        $this->title = $title;
        $this->options = $options;
    }

    public function handle(): void
    {
        if ($this->user && $this->title && !empty($this->options)) {
            $this->wasSuccessful = true;
        }
    }
}