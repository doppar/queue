<?php

namespace Doppar\Queue\Contracts;

use Doppar\Queue\Support\Envelope;
use Doppar\Queue\Support\FailedJobRecord;
use Doppar\Queue\Support\ReservedJob;

/**
 * The storage contract every queue backend implements.
 *
 * Guarantees a driver must provide:
 *  - Atomic claim: two workers never receive the same job at the same time.
 *  - Ordering: within a queue, higher priority first, then oldest first.
 *  - Leases: a claimed job is invisible to other workers until its lease
 *    expires, then it becomes claimable again with its attempts preserved.
 *  - Fencing: delete, release, extend and fail only succeed while the given
 *    ReservedJob is still the current reservation (matching attempts).
 *  - Uniqueness: an envelope with a unique key is refused while another job
 *    with the same key is pending or reserved.
 */
interface QueueDriver
{
    public const PRIORITY_MIN = -100;

    public const PRIORITY_MAX = 100;

    /**
     * Store a job.
     *
     * @param Envelope $envelope
     * @return bool
     */
    public function push(Envelope $envelope): bool;

    /**
     * Store many jobs, preserving their order.
     *
     * @param array<int, Envelope> $envelopes
     * @return int
     */
    public function pushMany(array $envelopes): int;

    /**
     * Claim the next available job, trying the queues in the order given.
     *
     * @param string|array<int, string> $queues
     * @param int|null $leaseFor
     * @return ReservedJob|null
     */
    public function pop(string|array $queues, ?int $leaseFor = null): ?ReservedJob;

    /**
     * Remove a finished job.
     *
     * @param ReservedJob $job
     * @return bool
     */
    public function delete(ReservedJob $job): bool;

    /**
     * Give a claimed job back, to run again after the delay.
     *
     * @param ReservedJob $job
     * @param int $delay Seconds
     * @return bool
     */
    public function release(ReservedJob $job, int $delay = 0): bool;

    /**
     * Push the lease of a claimed job out to the given number of seconds from now.
     *
     * @param ReservedJob $job
     * @param int $seconds
     * @return bool
     */
    public function extend(ReservedJob $job, int $seconds): bool;

    /**
     * Move a claimed job to the failed store.
     *
     * @param ReservedJob $job
     * @param string $exception Formatted exception text
     * @return bool
     */
    public function fail(ReservedJob $job, string $exception): bool;

    /**
     * Jobs on a queue that are not currently reserved (ready plus delayed).
     *
     * @param string $queue
     * @return int
     */
    public function size(string $queue): int;

    /**
     * Break a queue down by state.
     *
     * @param string $queue
     * @return array{ready: int, delayed: int, reserved: int}
     */
    public function stats(string $queue): array;

    /**
     * Names of the queues that currently hold jobs.
     *
     * @return array<int, string>
     */
    public function queues(): array;

    /**
     * Delete every job on a queue.
     *
     * @param string $queue
     * @return int
     */
    public function clear(string $queue): int;

    /**
     * All failed jobs, newest first.
     *
     * @return array<int, FailedJobRecord>
     */
    public function failedJobs(): array;

    /**
     * @param string|int $id
     * @return FailedJobRecord|null
     */
    public function findFailed(string|int $id): ?FailedJobRecord;

    /**
     * @param string|int $id
     * @return bool
     */
    public function forgetFailed(string|int $id): bool;

    /**
     * Delete every failed job.
     *
     * @return int
     */
    public function flushFailed(): int;

    /**
     * @return int
     */
    public function countFailed(): int;
}
