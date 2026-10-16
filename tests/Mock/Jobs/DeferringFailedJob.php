<?php

namespace Doppar\Queue\Tests\Mock\Jobs;

use Doppar\Queue\Job;

/**
 * Always fails and registers cleanup from failed(), which runs after the
 * job body has finished.
 */
class DeferringFailedJob extends Job
{
    public $tries = 1;

    /**
     * Runs inside failed() when set.
     *
     * @var \Closure|null
     */
    public static ?\Closure $inFailed = null;

    public function handle(): void
    {
        throw new \RuntimeException('always fails');
    }

    public function failed(\Throwable $exception): void
    {
        if (self::$inFailed !== null) {
            (self::$inFailed)($this);
        }
    }

    public static function reset(): void
    {
        self::$inFailed = null;
    }
}
