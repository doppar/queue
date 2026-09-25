<?php

namespace Doppar\Queue\Tests\Unit;

use Doppar\Queue\Contracts\QueueDriver;
use Doppar\Queue\Drain;
use Doppar\Queue\Drivers\MemoryDriver;
use Doppar\Queue\QueueManager;
use Doppar\Queue\QueueWorker;
use Doppar\Queue\Support\Envelope;
use Doppar\Queue\Support\ReservedJob;
use Doppar\Queue\Tests\Mock\Jobs\BackoffJob;
use Doppar\Queue\Tests\Mock\Jobs\FlakyJob;
use Doppar\Queue\Tests\Mock\Jobs\RecordingJob;
use Doppar\Queue\Tests\Mock\Jobs\UniqueRecordingJob;
use Doppar\Queue\Tests\Mock\MockContainer;
use Phaseolies\DI\Container;
use PHPUnit\Framework\TestCase;

class QueueWorkerTest extends TestCase
{
    private const LEASE = 60;

    private int $now = 1_800_000_000;

    private QueueManager $manager;

    private QueueWorker $worker;

    private string $output = '';

    protected function setUp(): void
    {
        $this->now = 1_800_000_000;
        RecordingJob::reset();
        FlakyJob::reset();

        $this->manager = new QueueManager([
            'default' => 'memory',
            'connections' => [
                'memory' => ['driver' => 'memory', 'lease' => self::LEASE],
                'other' => ['driver' => 'memory', 'lease' => self::LEASE],
            ],
        ], fn(): int => $this->now);

        // Job::dispatch(), chains and retries reach the manager through the facade.
        $container = new MockContainer();
        Container::setInstance($container);
        $container->singleton(QueueManager::class, fn() => $this->manager);
        $container->alias(QueueManager::class, 'queue.worker');

        $this->worker = new QueueWorker($this->manager);
    }

    protected function tearDown(): void
    {
        RecordingJob::reset();
        Container::forgetInstance();
    }

    private function driver(): QueueDriver
    {
        return $this->manager->connection();
    }

    private function advance(int $seconds): void
    {
        $this->now += $seconds;
    }

    /**
     * Run one job, capturing what the worker prints.
     */
    private function runOne(string|array $queue = 'default'): bool
    {
        ob_start();

        try {
            return $this->worker->runNextJob($queue);
        } finally {
            $this->output .= ob_get_clean();
        }
    }

    /**
     * Run jobs until nothing more can be claimed right now.
     */
    private function runAll(string|array $queue = 'default'): int
    {
        $count = 0;

        while ($count < 100 && $this->runOne($queue)) {
            $count++;
        }

        return $count;
    }

    // ------------------------------------------------------------------
    // Happy path
    // ------------------------------------------------------------------

    public function testAnEmptyQueueProcessesNothing(): void
    {
        $this->assertFalse($this->runOne());
        $this->assertSame(0, $this->worker->getJobsProcessed());
    }

    public function testAJobIsRunAndRemovedFromTheQueue(): void
    {
        $this->manager->push(new RecordingJob('a'));

        $this->assertTrue($this->runOne());

        $this->assertSame(['a'], RecordingJob::$handled);
        $this->assertSame(['ready' => 0, 'delayed' => 0, 'reserved' => 0], $this->driver()->stats('default'));
        $this->assertSame(1, $this->worker->getJobsProcessed());
        $this->assertSame(0, $this->driver()->countFailed());
    }

    public function testJobsRunInPriorityThenArrivalOrder(): void
    {
        $this->manager->push(new RecordingJob('normal-1'));
        $this->manager->push((new RecordingJob('urgent'))->withPriority(90));
        $this->manager->push(new RecordingJob('normal-2'));
        $this->manager->push((new RecordingJob('low'))->withPriority(-10));

        $this->runAll();

        $this->assertSame(['urgent', 'normal-1', 'normal-2', 'low'], RecordingJob::$handled);
    }

    public function testQueuesAreConsumedInTheOrderGiven(): void
    {
        $this->manager->push((new RecordingJob('low'))->onQueue('low'));
        $this->manager->push((new RecordingJob('default'))->onQueue('default'));
        $this->manager->push((new RecordingJob('high'))->onQueue('high'));

        $this->runAll('high,default,low');

        $this->assertSame(['high', 'default', 'low'], RecordingJob::$handled);
    }

    public function testEachRunWorksOnTheHighestPriorityQueueThatHasWork(): void
    {
        $this->manager->push((new RecordingJob('low-1'))->onQueue('low'));
        $this->manager->push((new RecordingJob('low-2'))->onQueue('low'));

        $this->runOne('high,low');
        $this->manager->push((new RecordingJob('high-arrives'))->onQueue('high'));
        $this->runAll('high,low');

        $this->assertSame(['low-1', 'high-arrives', 'low-2'], RecordingJob::$handled);
    }

    public function testProcessingCallbacksSeeTheJob(): void
    {
        $seen = [];
        $this->worker->setOnJobProcessing(function ($job) use (&$seen) {
            $seen[] = "before:{$job->label}:attempt{$job->attempts}";
        });
        $this->worker->setOnJobProcessed(function ($job) use (&$seen) {
            $seen[] = "after:{$job->label}";
        });

        $this->manager->push(new RecordingJob('cb'));
        $this->runOne();

        $this->assertSame(['before:cb:attempt1', 'after:cb'], $seen);
    }

    public function testTheWorkerCanConsumeANamedConnection(): void
    {
        $this->manager->push(new RecordingJob('on-other'), 'other');

        $this->assertFalse($this->runOne(), 'the default connection is empty');

        $this->worker->setConnection('other');

        $this->assertTrue($this->runOne());
        $this->assertSame(['on-other'], RecordingJob::$handled);
        $this->assertSame(0, $this->manager->size('default', 'other'));
    }

    public function testTheConnectionOptionIsReadFromDaemonOptions(): void
    {
        $method = new \ReflectionMethod($this->worker, 'configureOptions');
        $method->invoke($this->worker, ['connection' => 'other', 'sleep' => 0]);

        $this->manager->push(new RecordingJob('via-options'), 'other');

        $this->assertTrue($this->runOne());
    }

    // ------------------------------------------------------------------
    // Failure, retries and backoff
    // ------------------------------------------------------------------

    public function testAFailingJobIsReleasedAfterItsRetryAfterAndRetried(): void
    {
        $this->manager->push(new FlakyJob(failTimes: 2)); // tries 3, retryAfter 10

        $this->assertTrue($this->runOne());
        $this->assertSame(1, $this->driver()->stats('default')['delayed'], 'released, waiting for retryAfter');
        $this->assertFalse($this->runOne(), 'not available yet');

        $this->advance(10);
        $this->assertTrue($this->runOne());
        $this->assertSame(1, $this->driver()->stats('default')['delayed']);

        $this->advance(10);
        $this->assertTrue($this->runOne(), 'third attempt succeeds');

        $this->assertSame(3, FlakyJob::$calls);
        $this->assertSame(['ready' => 0, 'delayed' => 0, 'reserved' => 0], $this->driver()->stats('default'));
        $this->assertSame(0, $this->driver()->countFailed());
    }

    public function testAJobThatExhaustsItsTriesIsMovedToTheFailedStoreAndToldSo(): void
    {
        $this->manager->push(new FlakyJob(failTimes: 99)); // tries 3

        $this->runOne();
        $this->advance(10);
        $this->runOne();
        $this->advance(10);
        $this->runOne();

        $this->assertSame(3, FlakyJob::$calls);
        $this->assertSame(['flaky failure #3'], FlakyJob::$failedWith, 'failed() is called once, with the last error');
        $this->assertSame(1, $this->driver()->countFailed());
        $this->assertSame(0, $this->driver()->size('default'));

        $record = $this->driver()->failedJobs()[0];
        $this->assertStringContainsString('flaky failure #3', $record->exception);
        $this->assertInstanceOf(FlakyJob::class, $this->manager->unserializeJob($record->payload));
    }

    public function testASingleTryJobFailsImmediately(): void
    {
        $job = new FlakyJob(failTimes: 99);
        $job->tries = 1;
        $this->manager->push($job);

        $this->runOne();

        $this->assertSame(1, $this->driver()->countFailed());
        $this->assertSame(0, $this->driver()->stats('default')['delayed']);
    }

    public function testAJobWithZeroTriesStillRunsOnceThenFails(): void
    {
        $job = new FlakyJob(failTimes: 99);
        $job->tries = 0;
        $this->manager->push($job);

        $this->runOne();

        $this->assertSame(1, FlakyJob::$calls);
        $this->assertSame(1, $this->driver()->countFailed());
    }

    public function testBackoffListWaitsLongerEachTimeAndRepeatsItsLastValue(): void
    {
        $this->manager->push(new BackoffJob([10, 60, 300])); // tries 5

        $expected = [10, 60, 300, 300];

        foreach ($expected as $attempt => $wait) {
            $this->assertTrue($this->runOne(), 'attempt ' . ($attempt + 1));

            $this->advance($wait - 1);
            $this->assertFalse($this->runOne(), "must still be waiting ({$wait}s) after attempt " . ($attempt + 1));

            $this->advance(1);
        }

        $this->assertTrue($this->runOne(), 'fifth and last attempt');
        $this->assertSame(1, $this->driver()->countFailed());
    }

    public function testAnIntegerBackoffIsAFixedWait(): void
    {
        $this->manager->push(new BackoffJob(25));

        $this->runOne();

        $this->advance(24);
        $this->assertFalse($this->runOne());
        $this->advance(1);
        $this->assertTrue($this->runOne());
    }

    public function testWithoutABackoffTheRetryAfterIsUsed(): void
    {
        $this->manager->push(new BackoffJob(null)); // retryAfter 999

        $this->runOne();

        $this->advance(998);
        $this->assertFalse($this->runOne());
        $this->advance(1);
        $this->assertTrue($this->runOne());
    }

    public function testAnEmptyBackoffListFallsBackToRetryAfter(): void
    {
        $this->manager->push(new BackoffJob([]));

        $this->runOne();

        $this->advance(998);
        $this->assertFalse($this->runOne());
    }

    public function testANegativeBackoffMeansNoWait(): void
    {
        $this->manager->push(new BackoffJob(-5));

        $this->runOne();

        $this->assertTrue($this->runOne(), 'released with no delay');
    }

    // ------------------------------------------------------------------
    // Crashes, lost leases and broken payloads
    // ------------------------------------------------------------------

    public function testAJobThatKeepsCrashingItsWorkerIsFailedInsteadOfLoopingForever(): void
    {
        $this->manager->push(new RecordingJob('crasher')); // tries 1

        // A worker claims the job and dies without acknowledging it.
        $this->manager->pop();
        $this->advance(self::LEASE);

        $this->assertTrue($this->runOne());

        $this->assertSame([], RecordingJob::$handled, 'a job over its attempts must not run again');
        $this->assertSame(1, $this->driver()->countFailed());
        $this->assertStringContainsString('MaxAttemptsExceededException', $this->driver()->failedJobs()[0]->exception);
        $this->assertSame(0, $this->driver()->size('default'));
    }

    public function testACrashedJobWithTriesLeftIsRunByTheNextWorker(): void
    {
        $job = new RecordingJob('survivor');
        $job->tries = 3;
        $this->manager->push($job);

        $this->manager->pop();
        $this->advance(self::LEASE);

        $this->assertTrue($this->runOne());
        $this->assertSame(['survivor'], RecordingJob::$handled);
        $this->assertSame(0, $this->driver()->countFailed());
    }

    public function testFinishingAfterTheLeaseWasLostDoesNotBreakTheJobsNewOwner(): void
    {
        $this->manager->push(new RecordingJob('slow'));
        $stolen = null;

        RecordingJob::$during = function () use (&$stolen) {
            // The job runs so long that its lease expires and another worker takes it.
            $this->advance(self::LEASE);
            $stolen = $this->manager->pop();
        };

        $processed = [];
        $this->worker->setOnJobProcessed(function ($job) use (&$processed) {
            $processed[] = $job->label;
        });

        $this->assertTrue($this->runOne());

        $this->assertStringContainsString('lease was lost', $this->output);
        $this->assertSame([], $processed, 'follow-up work is left to the run that removes the job');
        $this->assertNotNull($stolen);
        $this->assertSame(2, $stolen->attempts);
        $this->assertSame(1, $this->driver()->stats('default')['reserved'], 'the new owner still holds the job');
        $this->assertSame(0, $this->driver()->countFailed());
        $this->assertTrue($this->driver()->delete($stolen), 'and can still finish it');
    }

    public function testAFailingRemovalAfterSuccessDoesNotRetryOrFailTheJob(): void
    {
        $this->manager->extend('memory', fn(array $config, \Closure $clock) => new class($config, $clock) extends MemoryDriver {
            public function delete(ReservedJob $job): bool
            {
                throw new \RuntimeException('backend went away');
            }
        });

        $this->manager->push(new RecordingJob('ran'));

        $this->assertTrue($this->runOne());

        $this->assertSame(['ran'], RecordingJob::$handled);
        $this->assertStringContainsString('could not be removed from the queue: backend went away', $this->output);
        $this->assertSame(0, $this->driver()->countFailed(), 'the job succeeded, it must not be failed');
        $this->assertSame(0, $this->driver()->stats('default')['delayed'], 'nor released for a retry');
        $this->assertSame(1, $this->driver()->stats('default')['reserved'], 'it stays leased and reappears when the lease expires');
    }

    public function testAnUnreadablePayloadIsFailedWithoutCrashingTheWorker(): void
    {
        $this->driver()->push(new Envelope('junk', 'default', 'not a serialized job', $this->now, 0, null, $this->now));
        $this->manager->push(new RecordingJob('after-junk'));

        $this->assertSame(2, $this->runAll());

        $this->assertSame(['after-junk'], RecordingJob::$handled);
        $this->assertSame(1, $this->driver()->countFailed());
        $this->assertStringContainsString('does not contain a valid job', $this->driver()->failedJobs()[0]->exception);
    }

    // ------------------------------------------------------------------
    // Uniqueness and chains
    // ------------------------------------------------------------------

    public function testAUniqueJobCanBeDispatchedAgainOnceItHasRun(): void
    {
        $this->assertNotNull($this->manager->push(new UniqueRecordingJob('report')));
        $this->assertNull($this->manager->push(new UniqueRecordingJob('report')));

        $this->runOne();

        $this->assertNotNull($this->manager->push(new UniqueRecordingJob('report')));
    }

    public function testAFailedUniqueJobFreesItsKey(): void
    {
        $unique = new UniqueRecordingJob('report');
        $unique->tries = 1;
        RecordingJob::$during = fn() => throw new \RuntimeException('nope');

        $this->manager->push($unique);
        $this->runOne();

        $this->assertSame(1, $this->driver()->countFailed());
        $this->assertNotNull($this->manager->push(new UniqueRecordingJob('report')));
    }

    public function testChainedJobsRunInOrderAcrossWorkerRuns(): void
    {
        Drain::conduct([new RecordingJob('one'), new RecordingJob('two'), new RecordingJob('three')])->dispatch();

        $this->assertSame(1, $this->driver()->size('default'), 'only the first job is queued up front');

        $this->assertSame(3, $this->runAll());
        $this->assertSame(['one', 'two', 'three'], RecordingJob::$handled);
    }

    public function testAChainStopsAtTheFirstFailure(): void
    {
        $failing = new FlakyJob(failTimes: 99);
        $failing->tries = 1;

        Drain::conduct([new RecordingJob('one'), $failing, new RecordingJob('never')])->dispatch();

        $this->runAll();

        $this->assertSame(['one'], RecordingJob::$handled);
        $this->assertSame(1, $this->driver()->countFailed());
        $this->assertSame(0, $this->driver()->size('default'));
    }

    public function testAChainedJobKeepsTheConnectionOfItsPredecessor(): void
    {
        $first = (new RecordingJob('one'))->onConnection('other');

        Drain::conduct([$first, new RecordingJob('two')])->dispatch();

        $this->worker->setConnection('other');

        $this->assertSame(2, $this->runAll());
        $this->assertSame(['one', 'two'], RecordingJob::$handled);
        $this->assertSame(0, $this->manager->size('default', 'memory'));
    }

    // ------------------------------------------------------------------
    // Lease renewal
    // ------------------------------------------------------------------

    private function startLongJob(int $renewedSecondsAgo): void
    {
        $this->manager->push(new RecordingJob('long'));
        $reserved = $this->manager->pop();

        $this->setProperty('reservation', $reserved);
        $this->setProperty('leaseRenewedAt', time() - $renewedSecondsAgo);
    }

    private function setProperty(string $name, mixed $value): void
    {
        (new \ReflectionProperty(QueueWorker::class, $name))->setValue($this->worker, $value);
    }

    private function renewLease(): void
    {
        (new \ReflectionMethod($this->worker, 'renewLease'))->invoke($this->worker);
    }

    public function testTheLeaseOfALongRunningJobIsRenewed(): void
    {
        $this->startLongJob(renewedSecondsAgo: 100);

        $this->advance(50);
        $this->renewLease(); // lease now runs to +110s

        $this->advance(30); // +80s: past the original lease, inside the renewed one

        $this->assertNull($this->manager->pop(), 'no other worker may take the job');
    }

    public function testTheLeaseIsNotRenewedMoreOftenThanNeeded(): void
    {
        $this->startLongJob(renewedSecondsAgo: 0);

        $this->advance(50);
        $this->renewLease(); // too soon: does nothing

        $this->advance(11); // +61s: the original lease has run out

        $this->assertNotNull($this->manager->pop());
    }

    public function testRenewingWithoutARunningJobDoesNothing(): void
    {
        $this->renewLease();

        $this->assertSame(0, $this->driver()->size('default'));
    }

    public function testAFailedRenewalIsLoggedNotThrown(): void
    {
        $this->startLongJob(renewedSecondsAgo: 100);
        $this->manager->extend('memory', fn(array $config, \Closure $clock) => new class($config, $clock) extends MemoryDriver {
            public function extend(ReservedJob $job, int $seconds): bool
            {
                throw new \RuntimeException('redis timeout');
            }
        });

        ob_start();
        $this->renewLease();
        $this->output .= ob_get_clean();

        $this->assertStringContainsString('Could not renew job lease: redis timeout', $this->output);
    }
}
