<?php

namespace Doppar\Queue\Tests\Mock\Jobs;

use Doppar\Queue\Job;
use Doppar\Queue\Dispatchable;

class TestNestedModelsJob extends Job
{
    use Dispatchable;

    protected $data;
    public $wasSuccessful = false;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function handle(): void
    {
        $primaryUser = $this->data['primary_user'] ?? null;
        $secondaryUser = $this->data['secondary_user'] ?? null;
        $post = $this->data['post'] ?? null;

        // Check if all models were restored
        if ($primaryUser && $secondaryUser && $post) {
            $this->wasSuccessful = true;
        }

        // Also check regular data is preserved
        if (isset($this->data['primary']) && isset($this->data['backup'])) {
            // Both references to same model
            $this->wasSuccessful = ($this->data['primary'] && $this->data['backup']);
        }
    }
}