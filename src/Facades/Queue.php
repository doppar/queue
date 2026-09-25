<?php

namespace Doppar\Queue\Facades;

use Doppar\Queue\Contracts\JobInterface;
use Doppar\Queue\Support\FailedJobRecord;
use Doppar\Queue\Support\ReservedJob;
use Phaseolies\Facade\BaseFacade;
use Doppar\Queue\QueueManager;

/**
 * @method static \Doppar\Queue\Contracts\QueueDriver connection(?string $name = null)
 * @method static string getDefaultConnection()
 * @method static void extend(string $driver, \Closure $factory)
 * @method static void purge(?string $name = null)
 * @method static string|null push(JobInterface $job, ?string $connection = null)
 * @method static int pushMany(array<int, JobInterface> $jobs, ?string $connection = null)
 * @method static ReservedJob|null pop(string|array<int, string> $queue = 'default', ?string $connection = null)
 * @method static bool delete(ReservedJob $queueJob, ?string $connection = null)
 * @method static bool release(ReservedJob $queueJob, int $delay = 0, ?string $connection = null)
 * @method static bool extendLease(ReservedJob $queueJob, int $seconds, ?string $connection = null)
 * @method static void markAsFailed(ReservedJob $queueJob, \Throwable $exception, ?string $connection = null)
 * @method static int size(string $queue = 'default', ?string $connection = null)
 * @method static array{ready: int, delayed: int, reserved: int} stats(string $queue = 'default', ?string $connection = null)
 * @method static int clear(string $queue = 'default', ?string $connection = null)
 * @method static bool retryFailed(FailedJobRecord|string|int $failed, ?string $connection = null)
 * @method static void setDefaultQueue(string $queue)
 * @method static string getDefaultQueue()
 *
 * @see QueueManager
 */
class Queue extends BaseFacade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'queue.worker';
    }
}
