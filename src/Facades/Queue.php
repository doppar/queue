<?php

namespace Doppar\Queue\Facades;

use Phaseolies\Queue\Models\QueueJob;
use Phaseolies\Queue\Contracts\JobInterface;
use Phaseolies\Facade\BaseFacade;
use Doppar\Queue\QueueManager;

/**
 * @method static string push(JobInterface $job)
 * @method static QueueJob|null pop(string $queue = 'default')
 * @method static bool delete(QueueJob $queueJob)
 * @method static bool release(QueueJob $queueJob, int $delay = 0)
 * @method static void markAsFailed(QueueJob $queueJob, \Throwable $exception)
 * @method static int size(string $queue = 'default')
 * @method static int clear(string $queue = 'default')
 * @method static void setDefaultQueue(string $queue)
 * @method static string getDefaultQueue()
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
