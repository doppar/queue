<?php

namespace Doppar\Queue\Tests\Mock\Jobs;

class UniqueRecordingJob extends RecordingJob
{
    public function uniqueId(): ?string
    {
        return $this->label;
    }
}
