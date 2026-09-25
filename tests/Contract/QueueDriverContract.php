<?php

namespace Doppar\Queue\Tests\Contract;

use Doppar\Queue\Contracts\QueueDriver;
use Doppar\Queue\Support\Envelope;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour every queue driver must provide. Each driver's test class extends
 * this and only says how to build its driver, so the guarantees are checked
 * identically against every backend.
 *
 * Time is injected: tests move `$this->now` instead of sleeping.
 */
abstract class QueueDriverContract extends TestCase
{
    protected const LEASE = 60;

    protected int $now = 1_800_000_000;

    protected QueueDriver $driver;

    /**
     * Build a driver with a lease of LEASE seconds that reads time from $clock.
     *
     * @param \Closure(): int $clock
     */
    abstract protected function makeDriver(\Closure $clock): QueueDriver;

    protected function setUp(): void
    {
        $this->now = 1_800_000_000;
        $this->driver = $this->makeDriver(fn(): int => $this->now);
    }

    protected function advance(int $seconds): void
    {
        $this->now += $seconds;
    }

    protected function push(
        string $id,
        string $queue = 'default',
        int $priority = 0,
        int $delay = 0,
        ?string $unique = null,
        ?string $payload = null,
    ): bool {
        return $this->driver->push(new Envelope(
            $id,
            $queue,
            $payload ?? "payload-{$id}",
            $this->now + $delay,
            $priority,
            $unique,
            $this->now
        ));
    }

    /**
     * Claim every currently claimable job and return their payloads in order.
     *
     * @return array<int, string>
     */
    protected function drain(string|array $queues = 'default'): array
    {
        $payloads = [];

        while (($job = $this->driver->pop($queues)) !== null) {
            $payloads[] = $job->payload;
        }

        return $payloads;
    }

    // ------------------------------------------------------------------
    // Claiming and ordering
    // ------------------------------------------------------------------

    public function testPopOnEmptyQueueReturnsNull(): void
    {
        $this->assertNull($this->driver->pop('default'));
    }

    public function testPopReturnsThePushedJobOnItsFirstAttempt(): void
    {
        $this->push('a', 'emails');

        $job = $this->driver->pop('emails');

        $this->assertNotNull($job);
        $this->assertSame('payload-a', $job->payload);
        $this->assertSame('emails', $job->queue);
        $this->assertSame(1, $job->attempts);
        $this->assertSame($this->now, $job->reservedAt);
        $this->assertSame($this->now + self::LEASE, $job->leaseExpiresAt);
    }

    public function testJobsAreClaimedInArrivalOrder(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $id) {
            $this->push($id);
        }

        $this->assertSame(['payload-a', 'payload-b', 'payload-c', 'payload-d'], $this->drain());
    }

    public function testHigherPriorityIsClaimedFirstAndTiesKeepArrivalOrder(): void
    {
        $this->push('low', priority: -5);
        $this->push('normal');
        $this->push('high-1', priority: 10);
        $this->push('high-2', priority: 10);
        $this->push('urgent', priority: 100);

        $this->assertSame(
            ['payload-urgent', 'payload-high-1', 'payload-high-2', 'payload-normal', 'payload-low'],
            $this->drain()
        );
    }

    public function testPrioritiesBeyondTheRangeAreClamped(): void
    {
        $this->push('max', priority: 100);
        $this->push('beyond-max', priority: 5000);
        $this->push('min', priority: -100);
        $this->push('beyond-min', priority: -5000);

        // Clamped values tie with the limit itself, so arrival order decides.
        $this->assertSame(
            ['payload-max', 'payload-beyond-max', 'payload-min', 'payload-beyond-min'],
            $this->drain()
        );
    }

    public function testDelayedJobBecomesClaimableExactlyWhenAvailable(): void
    {
        $this->push('later', delay: 30);

        $this->assertNull($this->driver->pop('default'));

        $this->advance(29);
        $this->assertNull($this->driver->pop('default'));

        $this->advance(1);
        $this->assertSame('payload-later', $this->driver->pop('default')?->payload);
    }

    public function testDelayedJobKeepsItsPriorityOnceAvailable(): void
    {
        $this->push('delayed-urgent', priority: 50, delay: 10);
        $this->push('now-normal');

        $this->advance(10);

        $this->assertSame(['payload-delayed-urgent', 'payload-now-normal'], $this->drain());
    }

    public function testPopTriesQueuesInTheOrderGiven(): void
    {
        $this->push('d1', 'default');
        $this->push('h1', 'high');
        $this->push('l1', 'low');

        $this->assertSame('payload-h1', $this->driver->pop(['high', 'default', 'low'])?->payload);
        $this->assertSame('payload-d1', $this->driver->pop(['high', 'default', 'low'])?->payload);
        $this->assertSame('payload-l1', $this->driver->pop(['high', 'default', 'low'])?->payload);
        $this->assertNull($this->driver->pop(['high', 'default', 'low']));
    }

    public function testPopAcceptsACommaSeparatedQueueList(): void
    {
        $this->push('d1', 'default');
        $this->push('h1', 'high');

        $this->assertSame('payload-h1', $this->driver->pop('high, default')?->payload);
        $this->assertSame('payload-d1', $this->driver->pop('high, default')?->payload);
    }

    public function testPopFallsThroughEmptyQueuesToTheNext(): void
    {
        $this->push('l1', 'low');

        $this->assertSame('payload-l1', $this->driver->pop(['high', 'default', 'low'])?->payload);
    }

    public function testQueuesAreIsolatedFromEachOther(): void
    {
        $this->push('a', 'one');
        $this->push('b', 'two');

        $this->assertSame(['payload-a'], $this->drain('one'));
        $this->assertSame(['payload-b'], $this->drain('two'));
    }

    public function testLeaseLengthCanBeOverriddenPerPop(): void
    {
        $this->push('a');

        $job = $this->driver->pop('default', 5);

        $this->assertSame($this->now + 5, $job->leaseExpiresAt);

        $this->advance(5);
        $this->assertSame(2, $this->driver->pop('default')?->attempts);
    }

    // ------------------------------------------------------------------
    // Leases, reclaim and fencing
    // ------------------------------------------------------------------

    public function testReservedJobIsInvisibleUntilItsLeaseExpires(): void
    {
        $this->push('a');
        $this->driver->pop('default');

        $this->assertNull($this->driver->pop('default'));

        $this->advance(self::LEASE - 1);
        $this->assertNull($this->driver->pop('default'));

        $this->advance(1);
        $this->assertSame('payload-a', $this->driver->pop('default')?->payload);
    }

    public function testAttemptsAccumulateAcrossReclaims(): void
    {
        $this->push('a');

        foreach ([1, 2, 3] as $expected) {
            $job = $this->driver->pop('default');
            $this->assertSame($expected, $job->attempts);
            $this->advance(self::LEASE);
        }
    }

    public function testStaleReservationCannotTouchAJobAnotherWorkerTookOver(): void
    {
        $this->push('a');

        $first = $this->driver->pop('default');
        $this->advance(self::LEASE);
        $second = $this->driver->pop('default');

        $this->assertSame(2, $second->attempts);

        $this->assertFalse($this->driver->extend($first, 60), 'stale extend');
        $this->assertFalse($this->driver->release($first), 'stale release');
        $this->assertFalse($this->driver->fail($first, 'boom'), 'stale fail');
        $this->assertFalse($this->driver->delete($first), 'stale delete');

        $this->assertSame(0, $this->driver->countFailed());
        $this->assertSame(1, $this->driver->stats('default')['reserved']);
        $this->assertTrue($this->driver->delete($second), 'current reservation still works');
    }

    public function testReleasedReservationCannotBeUsedAgain(): void
    {
        $this->push('a');

        $job = $this->driver->pop('default');
        $this->assertTrue($this->driver->release($job));

        $this->assertFalse($this->driver->delete($job));
        $this->assertFalse($this->driver->extend($job, 30));
        $this->assertSame(1, $this->driver->size('default'), 'the released job is still queued');
    }

    public function testExtendKeepsAJobReservedPastItsOriginalLease(): void
    {
        $this->push('a');
        $job = $this->driver->pop('default');

        $this->advance(50);
        $this->assertTrue($this->driver->extend($job, 60));

        $this->advance(30); // 80s in: past the original lease, inside the extended one
        $this->assertNull($this->driver->pop('default'));

        $this->advance(30); // 110s in: extended lease (50 + 60) has run out
        $this->assertSame(2, $this->driver->pop('default')?->attempts);
    }

    // ------------------------------------------------------------------
    // Delete and release
    // ------------------------------------------------------------------

    public function testDeleteRemovesTheJob(): void
    {
        $this->push('a');
        $job = $this->driver->pop('default');

        $this->assertTrue($this->driver->delete($job));
        $this->assertFalse($this->driver->delete($job), 'a second delete has nothing to remove');

        $this->advance(self::LEASE * 2);
        $this->assertNull($this->driver->pop('default'));
        $this->assertSame(['ready' => 0, 'delayed' => 0, 'reserved' => 0], $this->driver->stats('default'));
    }

    public function testReleaseMakesTheJobClaimableAgainWithItsAttemptsKept(): void
    {
        $this->push('a');
        $this->assertTrue($this->driver->release($this->driver->pop('default')));

        $again = $this->driver->pop('default');

        $this->assertSame('payload-a', $again->payload);
        $this->assertSame(2, $again->attempts);
    }

    public function testReleaseWithDelayHoldsTheJobBack(): void
    {
        $this->push('a');
        $this->driver->release($this->driver->pop('default'), 20);

        $this->assertNull($this->driver->pop('default'));
        $this->assertSame(1, $this->driver->stats('default')['delayed']);

        $this->advance(20);
        $this->assertSame('payload-a', $this->driver->pop('default')?->payload);
    }

    public function testReleasedJobKeepsItsPlaceInLine(): void
    {
        $this->push('first');
        $this->push('second');

        $this->driver->release($this->driver->pop('default'));

        $this->assertSame(['payload-first', 'payload-second'], $this->drain());
    }

    // ------------------------------------------------------------------
    // Failed jobs
    // ------------------------------------------------------------------

    public function testFailMovesTheJobToTheFailedStore(): void
    {
        $this->push('a', 'emails');
        $job = $this->driver->pop('emails');

        $this->assertTrue($this->driver->fail($job, 'RuntimeException: boom'));

        $this->assertSame(1, $this->driver->countFailed());
        $this->assertSame(0, $this->driver->size('emails'));
        $this->assertSame(0, $this->driver->stats('emails')['reserved']);

        $record = $this->driver->failedJobs()[0];
        $this->assertSame('emails', $record->queue);
        $this->assertSame('payload-a', $record->payload);
        $this->assertSame('RuntimeException: boom', $record->exception);
        $this->assertSame($this->now, $record->failedAt);
        $this->assertNotSame('', $record->connection);
    }

    public function testFailedJobsAreListedNewestFirst(): void
    {
        foreach (['a', 'b', 'c'] as $id) {
            $this->push($id);
            $this->driver->fail($this->driver->pop('default'), "failure {$id}");
            $this->advance(10);
        }

        $this->assertSame(
            ['failure c', 'failure b', 'failure a'],
            array_map(fn($record) => $record->exception, $this->driver->failedJobs())
        );
    }

    public function testFailedJobsCanBeFoundForgottenAndFlushed(): void
    {
        foreach (['a', 'b', 'c'] as $id) {
            $this->push($id);
            $this->driver->fail($this->driver->pop('default'), "failure {$id}");
        }

        $records = $this->driver->failedJobs();
        $target = $records[1];

        $this->assertSame($target->payload, $this->driver->findFailed($target->id)?->payload);
        $this->assertTrue($this->driver->forgetFailed($target->id));
        $this->assertNull($this->driver->findFailed($target->id));
        $this->assertFalse($this->driver->forgetFailed($target->id));
        $this->assertSame(2, $this->driver->countFailed());

        $this->assertSame(2, $this->driver->flushFailed());
        $this->assertSame(0, $this->driver->countFailed());
        $this->assertSame([], $this->driver->failedJobs());
    }

    public function testUnknownFailedJobIsNotFound(): void
    {
        $this->assertNull($this->driver->findFailed(999999));
        $this->assertFalse($this->driver->forgetFailed(999999));
    }

    // ------------------------------------------------------------------
    // Uniqueness
    // ------------------------------------------------------------------

    public function testUniqueKeyRefusesADuplicateWhileTheFirstIsQueued(): void
    {
        $this->assertTrue($this->push('a', unique: 'report:7'));
        $this->assertFalse($this->push('b', unique: 'report:7'));

        $this->assertSame(['payload-a'], $this->drain());
    }

    public function testDifferentUniqueKeysAndMissingKeysNeverConflict(): void
    {
        $this->assertTrue($this->push('a', unique: 'report:1'));
        $this->assertTrue($this->push('b', unique: 'report:2'));
        $this->assertTrue($this->push('c'));
        $this->assertTrue($this->push('d'));

        $this->assertSame(4, $this->driver->size('default'));
    }

    public function testUniqueKeyIsHeldWhileTheJobIsReservedAndAfterRelease(): void
    {
        $this->push('a', unique: 'k');
        $job = $this->driver->pop('default');

        $this->assertFalse($this->push('b', unique: 'k'), 'held while reserved');

        $this->driver->release($job);
        $this->assertFalse($this->push('c', unique: 'k'), 'held after release');
    }

    public function testUniqueKeyIsFreedWhenTheJobIsDeleted(): void
    {
        $this->push('a', unique: 'k');
        $this->driver->delete($this->driver->pop('default'));

        $this->assertTrue($this->push('b', unique: 'k'));
    }

    public function testUniqueKeyIsFreedWhenTheJobFails(): void
    {
        $this->push('a', unique: 'k');
        $this->driver->fail($this->driver->pop('default'), 'boom');

        $this->assertTrue($this->push('b', unique: 'k'));
    }

    public function testUniqueKeyIsFreedWhenTheQueueIsCleared(): void
    {
        $this->push('a', unique: 'k');
        $this->driver->clear('default');

        $this->assertTrue($this->push('b', unique: 'k'));
    }

    public function testStaleReservationDoesNotFreeAUniqueKeyItNoLongerOwns(): void
    {
        $this->push('a', unique: 'k');
        $first = $this->driver->pop('default');
        $this->advance(self::LEASE);
        $this->driver->pop('default');

        $this->assertFalse($this->driver->delete($first));
        $this->assertFalse($this->push('b', unique: 'k'), 'the live job still owns the key');
    }

    // ------------------------------------------------------------------
    // Bulk push, sizes and housekeeping
    // ------------------------------------------------------------------

    public function testPushManyStoresJobsInOrder(): void
    {
        $stored = $this->driver->pushMany([
            new Envelope('a', 'default', 'payload-a', $this->now, 0, null, $this->now),
            new Envelope('b', 'default', 'payload-b', $this->now, 0, null, $this->now),
            new Envelope('c', 'other', 'payload-c', $this->now, 0, null, $this->now),
        ]);

        $this->assertSame(3, $stored);
        $this->assertSame(['payload-a', 'payload-b'], $this->drain('default'));
        $this->assertSame(['payload-c'], $this->drain('other'));
    }

    public function testPushManySkipsDuplicatesOfUniqueJobsAndKeepsTheRestInOrder(): void
    {
        $this->push('existing', unique: 'k');

        $stored = $this->driver->pushMany([
            new Envelope('a', 'default', 'payload-a', $this->now, 0, null, $this->now),
            new Envelope('dup', 'default', 'payload-dup', $this->now, 0, 'k', $this->now),
            new Envelope('b', 'default', 'payload-b', $this->now, 0, null, $this->now),
            new Envelope('fresh', 'default', 'payload-fresh', $this->now, 0, 'other-key', $this->now),
            new Envelope('dup-in-batch', 'default', 'payload-dup2', $this->now, 0, 'other-key', $this->now),
        ]);

        $this->assertSame(3, $stored);
        $this->assertSame(
            ['payload-existing', 'payload-a', 'payload-b', 'payload-fresh'],
            $this->drain()
        );
    }

    public function testPushManyHandlesLargeBatches(): void
    {
        $envelopes = [];

        for ($i = 0; $i < 450; $i++) {
            $envelopes[] = new Envelope("job-{$i}", 'bulk', "payload-{$i}", $this->now, 0, null, $this->now);
        }

        $this->assertSame(450, $this->driver->pushMany($envelopes));
        $this->assertSame(450, $this->driver->size('bulk'));

        $drained = $this->drain('bulk');

        $this->assertCount(450, $drained);
        $this->assertSame('payload-0', $drained[0]);
        $this->assertSame('payload-449', $drained[449]);
    }

    public function testPushManyWithNoJobsIsANoOp(): void
    {
        $this->assertSame(0, $this->driver->pushMany([]));
    }

    public function testStatsSeparateReadyDelayedAndReservedJobs(): void
    {
        $this->push('ready-1');
        $this->push('ready-2');
        $this->push('delayed', delay: 100);
        $this->push('will-be-reserved');
        $this->driver->pop('default');

        $this->assertSame(['ready' => 2, 'delayed' => 1, 'reserved' => 1], $this->driver->stats('default'));
    }

    public function testSizeCountsReadyAndDelayedButNotReserved(): void
    {
        $this->push('a');
        $this->push('b', delay: 100);
        $this->push('c');
        $this->driver->pop('default');

        $this->assertSame(2, $this->driver->size('default'));
    }

    public function testJobWithExpiredLeaseCountsAsReady(): void
    {
        $this->push('a');
        $this->driver->pop('default');

        $this->advance(self::LEASE);

        $this->assertSame(['ready' => 1, 'delayed' => 0, 'reserved' => 0], $this->driver->stats('default'));
    }

    public function testStatsForAnUnknownQueueAreZero(): void
    {
        $this->assertSame(['ready' => 0, 'delayed' => 0, 'reserved' => 0], $this->driver->stats('nope'));
        $this->assertSame(0, $this->driver->size('nope'));
    }

    public function testQueuesListsOnlyQueuesThatHoldJobs(): void
    {
        $this->push('a', 'emails');
        $this->push('b', 'reports');
        $this->push('c', 'gone');
        $this->driver->clear('gone');

        $this->assertSame(['emails', 'reports'], $this->driver->queues());
    }

    public function testClearRemovesReadyDelayedAndReservedJobsOfOneQueue(): void
    {
        $this->push('ready', 'target');
        $this->push('delayed', 'target', delay: 100);
        $this->push('reserved', 'target');
        $this->driver->pop('target');
        $this->push('bystander', 'other');

        $this->assertSame(3, $this->driver->clear('target'));

        $this->assertSame(['ready' => 0, 'delayed' => 0, 'reserved' => 0], $this->driver->stats('target'));
        $this->assertSame(['payload-bystander'], $this->drain('other'));
    }

    public function testClearOnAnEmptyQueueReturnsZero(): void
    {
        $this->assertSame(0, $this->driver->clear('nothing'));
    }

    public function testClearHandlesMoreJobsThanOneBatch(): void
    {
        $envelopes = [];

        for ($i = 0; $i < 1500; $i++) {
            $envelopes[] = new Envelope("job-{$i}", 'big', 'x', $this->now, 0, null, $this->now);
        }

        $this->driver->pushMany($envelopes);

        $this->assertSame(1500, $this->driver->clear('big'));
        $this->assertSame(0, $this->driver->size('big'));
    }

    // ------------------------------------------------------------------
    // Payload fidelity
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function payloads(): array
    {
        return [
            'ascii' => ['plain text'],
            'unicode' => ['ব্যবহারকারী — naïve café 日本語 🚀'],
            'null bytes and binary' => ["a\0b\xFF\xFEc\x01\x02"],
            'serialized php' => [serialize(['job' => new \ArrayObject([1, 2, 3]), 'n' => 1.5])],
            'quotes and sql' => ["'; DROP TABLE queue_jobs; -- \" \\ %s ?"],
            'large (200 KB)' => [str_repeat('0123456789abcdef', 12_800)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('payloads')]
    public function testPayloadsSurviveARoundTripByteForByte(string $payload): void
    {
        $this->push('a', payload: $payload);

        $this->assertSame($payload, $this->driver->pop('default')?->payload);
    }

    public function testFailedPayloadAndExceptionSurviveByteForByte(): void
    {
        $payload = "bin\0ary ব্যবহারকারী";
        $exception = "Error: \"quoted\" \0 trace\nline 2";

        $this->push('a', payload: $payload);
        $this->driver->fail($this->driver->pop('default'), $exception);

        $record = $this->driver->failedJobs()[0];

        $this->assertSame($payload, $record->payload);
        $this->assertSame($exception, $record->exception);
    }
}
