<?php

namespace Doppar\Queue\Tests\Mock\Jobs;

use Doppar\Queue\Tests\Mock\Models\MockUser;
use Doppar\Queue\Tests\Mock\Models\MockPost;
use Doppar\Queue\Tests\Mock\Models\MockComment;
use Doppar\Queue\Job;
use Doppar\Queue\Dispatchable;

class TestMultipleModelsJob extends Job
{
    use Dispatchable;

    protected $user;
    protected $post;
    protected $comment;
    public $wasSuccessful = false;

    public function __construct(MockUser $user, MockPost $post, MockComment $comment)
    {
        $this->user = $user;
        $this->post = $post;
        $this->comment = $comment;
    }

    public function handle(): void
    {
        // Check all models exist
        if ($this->user && $this->post && $this->comment) {
            $this->wasSuccessful = true;
        }
    }
}
