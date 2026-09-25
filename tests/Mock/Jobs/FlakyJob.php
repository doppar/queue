<?php

namespace Doppar\Queue\Tests\Mock\Jobs;

use Doppar\Queue\Job;

/**
 * Fails its first $failTimes runs, then succeeds.
 */
class FlakyJob extends Job
{
    public static int $calls = 0;

    public static array $failedWith = [];

    public $tries = 3;

    public $retryAfter = 10;

    public function __construct(public int $failTimes = 2)
    {
    }

    public function handle(): void
    {
        self::$calls++;

        if (self::$calls <= $this->failTimes) {
            throw new \RuntimeException('flaky failure #' . self::$calls);
        }
    }

    public function failed(\Throwable $exception): void
    {
        self::$failedWith[] = $exception->getMessage();
    }

    public static function reset(): void
    {
        self::$calls = 0;
        self::$failedWith = [];
    }
}
