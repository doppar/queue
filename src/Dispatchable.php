<?php

namespace Doppar\Queue;

trait Dispatchable
{
    /**
     * Dispatch the job with the given arguments.
     *
     * @param mixed ...$args
     * @return string|null Job ID if queued, null if executed immediately
     */
    public static function dispatchWith(...$args): ?string
    {
        return (new static(...$args))->dispatch();
    }

    /**
     * Dispatch the job synchronously with the given arguments.
     *
     * @param mixed ...$args
     * @return void
     */
    public static function queueAsSync(...$args): void
    {
        (new static(...$args))->handle();
    }

    /**
     * Dispatch the job after a delay with the given arguments.
     *
     * @param int $delay
     * @param mixed ...$args
     * @return string Job ID
     */
    public static function queueAfter(int $delay, ...$args): string
    {
        return (new static(...$args))->delayFor($delay)->forceQueue();
    }

    /**
     * Dispatch the job to a specific queue with the given arguments.
     *
     * @param string $queue
     * @param mixed ...$args
     * @return string Job ID
     */
    public static function queueOn(string $queue, ...$args): string
    {
        return (new static(...$args))->onQueue($queue)->forceQueue();
    }
}
