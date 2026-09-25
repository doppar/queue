<?php

namespace Doppar\Queue\Tests\Mock\Jobs;

use Doppar\Queue\Job;

/**
 * Records that it ran, in order, in a static list that the test can read.
 */
class RecordingJob extends Job
{
    /**
     * @var array<int, string>
     */
    public static array $handled = [];

    /**
     * Runs inside handle() when set, to let a test interfere mid-job.
     *
     * @var \Closure|null
     */
    public static ?\Closure $during = null;

    public function __construct(public string $label = 'job')
    {
    }

    public function handle(): void
    {
        if (self::$during !== null) {
            (self::$during)($this);
        }

        self::$handled[] = $this->label;
    }

    public static function reset(): void
    {
        self::$handled = [];
        self::$during = null;
    }
}
