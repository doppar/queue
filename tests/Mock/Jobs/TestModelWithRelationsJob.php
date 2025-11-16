<?php

namespace Doppar\Queue\Tests\Mock\Jobs;

use Doppar\Queue\Tests\Mock\Models\MockUser;
use Doppar\Queue\Tests\Mock\Models\MockPost;
use Doppar\Queue\Job;
use Doppar\Queue\Dispatchable;

class TestModelWithRelationsJob extends Job
{
    use Dispatchable;

    protected $user;
    public $postCount = 0;

    public function __construct(MockUser $user)
    {
        $this->user = $user;
    }

    public function handle(): void
    {
        if ($this->user === null) {
            return;
        }

        // Relationships must be reloaded
        $posts = MockPost::where('user_id', $this->user->id)->get();
        $this->postCount = $posts->count();
    }
}