<?php

namespace Doppar\Queue\Tests\Unit;

use Doppar\Queue\Contracts\QueueDriver;
use Doppar\Queue\Drivers\DatabaseDriver;
use Doppar\Queue\Drivers\MemoryDriver;
use Doppar\Queue\Drivers\RedisDriver;
use Doppar\Queue\Exceptions\QueueException;
use Doppar\Queue\QueueManager;
use Doppar\Queue\Support\Envelope;
use Doppar\Queue\Support\FailedJobRecord;
use Doppar\Queue\Support\ReservedJob;
use Doppar\Queue\Tests\Mock\Jobs\LegacyJob;
use Doppar\Queue\Tests\Mock\Jobs\OtherUniqueRecordingJob;
use Doppar\Queue\Tests\Mock\Jobs\RecordingJob;
use Doppar\Queue\Tests\Mock\Jobs\UniqueRecordingJob;
use PHPUnit\Framework\TestCase;

class QueueManagerTest extends TestCase
{
    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        $this->now = 1_800_000_000;
        RecordingJob::reset();
        LegacyJob::$handled = 0;
    }

    /**
     * @param array<string, mixed> $connections
     */
    private function manager(array $connections = [], string $default = 'memory'): QueueManager
    {
        return new QueueManager([
            'default' => $default,
            'connections' => $connections + [
                'memory' => ['driver' => 'memory', 'lease' => 60],
                'other' => ['driver' => 'memory', 'lease' => 60],
            ],
        ], fn(): int => $this->now);
    }

    // ------------------------------------------------------------------
    // Configuration and connections
    // ------------------------------------------------------------------

    public function testWithoutConfigurationItFallsBackToTheDatabaseConnection(): void
    {
        $manager = new QueueManager();

        $this->assertSame('database', $manager->getDefaultConnection());
        $this->assertInstanceOf(DatabaseDriver::class, $manager->connection());
    }

    public function testBuiltInDriversAreResolvedByName(): void
    {
        $manager = $this->manager([
            'db' => ['driver' => 'database'],
            'cache' => ['driver' => 'redis'],
            'ram' => ['driver' => 'array'],
        ]);

        $this->assertInstanceOf(DatabaseDriver::class, $manager->connection('db'));
        $this->assertInstanceOf(RedisDriver::class, $manager->connection('cache'));
        $this->assertInstanceOf(MemoryDriver::class, $manager->connection('ram'));
    }

    public function testADriverDefaultsToTheConnectionName(): void
    {
        $manager = new QueueManager(['default' => 'memory', 'connections' => ['memory' => []]]);

        $this->assertInstanceOf(MemoryDriver::class, $manager->connection());
    }

    public function testConnectionsAreResolvedOnceAndCached(): void
    {
        $manager = $this->manager();

        $this->assertSame($manager->connection('memory'), $manager->connection('memory'));
        $this->assertSame($manager->connection(), $manager->connection('memory'));
        $this->assertNotSame($manager->connection('memory'), $manager->connection('other'));
    }

    public function testPurgeRebuildsAConnectionOnNextUse(): void
    {
        $manager = $this->manager();
        $first = $manager->connection('memory');

        $manager->purge('memory');

        $this->assertNotSame($first, $manager->connection('memory'));

        $second = $manager->connection('memory');
        $other = $manager->connection('other');
        $manager->purge();

        $this->assertNotSame($second, $manager->connection('memory'));
        $this->assertNotSame($other, $manager->connection('other'));
    }

    public function testAnUnconfiguredConnectionIsRejected(): void
    {
        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('Queue connection [nowhere] is not configured.');

        $this->manager()->connection('nowhere');
    }

    public function testAnUnsupportedDriverIsRejected(): void
    {
        $manager = $this->manager(['odd' => ['driver' => 'carrier-pigeon']]);

        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('Queue driver [carrier-pigeon] is not supported.');

        $manager->connection('odd');
    }

    public function testCustomDriversReceiveTheirConfigAndTheClock(): void
    {
        $manager = $this->manager(['custom' => ['driver' => 'sqs', 'region' => 'eu-west-1']]);
        $received = null;

        $manager->extend('sqs', function (array $config, \Closure $clock) use (&$received): QueueDriver {
            $received = [$config, $clock()];

            return new MemoryDriver($config, $clock);
        });

        $this->assertInstanceOf(MemoryDriver::class, $manager->connection('custom'));
        $this->assertSame('eu-west-1', $received[0]['region']);
        $this->assertSame('custom', $received[0]['name']);
        $this->assertSame($this->now, $received[1]);
    }

    public function testACustomDriverOverridesABuiltInOne(): void
    {
        $manager = $this->manager();
        $custom = new MemoryDriver();

        $manager->extend('memory', fn() => $custom);

        $this->assertSame($custom, $manager->connection('memory'));
    }

    public function testRegisteringACustomDriverDropsAlreadyResolvedConnections(): void
    {
        $manager = $this->manager();
        $before = $manager->connection('memory');

        $manager->extend('memory', fn() => new MemoryDriver());

        $this->assertNotSame($before, $manager->connection('memory'));
    }

    public function testConfigFromTheContainerIsMergedOverTheBuiltInDefaults(): void
    {
        $manager = new class extends QueueManager {
            protected function config(): array
            {
                return ['default' => 'memory', 'connections' => ['memory' => ['driver' => 'memory']]];
            }
        };

        $this->assertSame('memory', $manager->getDefaultConnection());
    }

    public function testTheDefaultQueueNameCanBeChanged(): void
    {
        $manager = $this->manager();

        $this->assertSame('default', $manager->getDefaultQueue());

        $manager->setDefaultQueue('emails');

        $this->assertSame('emails', $manager->getDefaultQueue());
    }

    // ------------------------------------------------------------------
    // Pushing
    // ------------------------------------------------------------------

    public function testPushStampsAJobIdAndStoresAnUnserializablePayload(): void
    {
        $manager = $this->manager();
        $job = new RecordingJob('hello');

        $id = $manager->push($job);

        $this->assertStringStartsWith('job_', $id);
        $this->assertSame($id, $job->getJobId());

        $reserved = $manager->pop();
        $restored = $manager->unserializeJob($reserved->payload);

        $this->assertInstanceOf(RecordingJob::class, $restored);
        $this->assertSame('hello', $restored->label);
        $this->assertSame($id, $restored->getJobId());
    }

    public function testEveryPushGetsADistinctJobId(): void
    {
        $manager = $this->manager();

        $ids = [];
        for ($i = 0; $i < 200; $i++) {
            $ids[] = $manager->push(new RecordingJob());
        }

        $this->assertCount(200, array_unique($ids));
    }

    public function testDelayIsAppliedFromTheInjectedClock(): void
    {
        $manager = $this->manager();
        $manager->push((new RecordingJob())->delayFor(30));

        $this->assertNull($manager->pop());

        $this->now += 30;

        $this->assertNotNull($manager->pop());
    }

    public function testJobQueueDecidesWhereItIsStored(): void
    {
        $manager = $this->manager();
        $manager->push((new RecordingJob())->onQueue('emails'));

        $this->assertSame(0, $manager->size('default'));
        $this->assertSame(1, $manager->size('emails'));
    }

    public function testPriorityFromTheJobOrdersClaims(): void
    {
        $manager = $this->manager();
        $manager->push(new RecordingJob('normal'));
        $manager->push((new RecordingJob('urgent'))->withPriority(50));

        $this->assertSame('urgent', $manager->unserializeJob($manager->pop()->payload)->label);
        $this->assertSame('normal', $manager->unserializeJob($manager->pop()->payload)->label);
    }

    public function testUniqueJobIsRefusedWhileAnotherWithTheSameKeyIsQueued(): void
    {
        $manager = $this->manager();

        $this->assertNotNull($manager->push(new UniqueRecordingJob('report-7')));
        $this->assertNull($manager->push(new UniqueRecordingJob('report-7')));
        $this->assertNotNull($manager->push(new UniqueRecordingJob('report-8')));

        $this->assertSame(2, $manager->size());
    }

    public function testUniqueJobCanBeQueuedAgainOnceTheFirstIsDone(): void
    {
        $manager = $this->manager();
        $manager->push(new UniqueRecordingJob('report-7'));

        $manager->delete($manager->pop());

        $this->assertNotNull($manager->push(new UniqueRecordingJob('report-7')));
    }

    public function testUniqueKeysAreScopedPerJobClass(): void
    {
        $manager = $this->manager();

        $this->assertNotNull($manager->push(new UniqueRecordingJob('same')));
        $this->assertNotNull($manager->push(new OtherUniqueRecordingJob('same')));
    }

    public function testVeryLongUniqueIdsStayWithinIndexLimits(): void
    {
        $manager = new class(['default' => 'spy', 'connections' => ['spy' => ['driver' => 'spy']]]) extends QueueManager {
            public ?Envelope $last = null;

            public function envelope($job): Envelope
            {
                return $this->last = $this->envelopeFor($job);
            }
        };

        $long = str_repeat('x', 500);
        $envelope = $manager->envelope(new UniqueRecordingJob($long));

        $this->assertLessThanOrEqual(191, strlen($envelope->uniqueKey));
        $this->assertSame($envelope->uniqueKey, $manager->envelope(new UniqueRecordingJob($long))->uniqueKey);
        $this->assertNotSame($envelope->uniqueKey, $manager->envelope(new UniqueRecordingJob($long . 'y'))->uniqueKey);
    }

    public function testJobsThatOnlyImplementTheOriginalInterfaceStillWork(): void
    {
        $manager = $this->manager();
        $job = new LegacyJob();

        $this->assertNotNull($manager->push($job));

        $reserved = $manager->pop('legacy');

        $this->assertNotNull($reserved);
        $this->assertInstanceOf(LegacyJob::class, $manager->unserializeJob($reserved->payload));
    }

    public function testTheJobsOwnConnectionWinsOverTheArgumentWhichWinsOverTheDefault(): void
    {
        $manager = $this->manager();

        $manager->push((new RecordingJob('a'))->onConnection('other'));
        $manager->push(new RecordingJob('b'), 'other');
        $manager->push((new RecordingJob('c'))->onConnection('memory'), 'other');
        $manager->push(new RecordingJob('d'));

        $this->assertSame(2, $manager->size('default', 'other'));
        $this->assertSame(2, $manager->size('default', 'memory'));
    }

    public function testAFailingDriverIsReportedAsAQueueException(): void
    {
        $manager = $this->manager();
        $manager->extend('memory', fn() => new class extends MemoryDriver {
            public function push(Envelope $envelope): bool
            {
                throw new \RuntimeException('backend down');
            }
        });

        try {
            $manager->push(new RecordingJob());
            $this->fail('push should have thrown');
        } catch (QueueException $e) {
            $this->assertStringContainsString('backend down', $e->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }

    public function testPushManyStoresJobsInOrderAndGroupsThemByConnection(): void
    {
        $manager = $this->manager();

        $stored = $manager->pushMany([
            new RecordingJob('a'),
            (new RecordingJob('b'))->onConnection('other'),
            new RecordingJob('c'),
            (new RecordingJob('d'))->onConnection('other'),
        ]);

        $this->assertSame(4, $stored);
        $this->assertSame(2, $manager->size('default', 'memory'));
        $this->assertSame(2, $manager->size('default', 'other'));
        $this->assertSame('a', $manager->unserializeJob($manager->pop()->payload)->label);
        $this->assertSame('c', $manager->unserializeJob($manager->pop()->payload)->label);
        $this->assertSame('b', $manager->unserializeJob($manager->pop('default', 'other')->payload)->label);
    }

    public function testPushManySkipsRefusedDuplicatesInTheCount(): void
    {
        $manager = $this->manager();
        $manager->push(new UniqueRecordingJob('k'));

        $this->assertSame(1, $manager->pushMany([
            new UniqueRecordingJob('k'),
            new UniqueRecordingJob('fresh'),
        ]));
    }

    public function testPushManyWithNoJobsStoresNothing(): void
    {
        $this->assertSame(0, $this->manager()->pushMany([]));
    }

    // ------------------------------------------------------------------
    // Consuming, stats and the failed store
    // ------------------------------------------------------------------

    public function testPopAcceptsQueueListsInPriorityOrder(): void
    {
        $manager = $this->manager();
        $manager->push((new RecordingJob('low'))->onQueue('low'));
        $manager->push((new RecordingJob('high'))->onQueue('high'));

        $this->assertSame('high', $manager->unserializeJob($manager->pop('high,low')->payload)->label);
        $this->assertSame('low', $manager->unserializeJob($manager->pop(['high', 'low'])->payload)->label);
    }

    public function testDeleteReleaseAndExtendLeaseAct(): void
    {
        $manager = $this->manager();
        $manager->push(new RecordingJob());

        $reserved = $manager->pop();
        $this->assertTrue($manager->extendLease($reserved, 120));
        $this->assertTrue($manager->release($reserved, 5));
        $this->assertSame(1, $manager->stats()['delayed']);

        $this->now += 5;
        $again = $manager->pop();

        $this->assertTrue($manager->delete($again));
        $this->assertSame(0, $manager->size());
    }

    public function testMarkAsFailedStoresAFormattedException(): void
    {
        $manager = $this->manager();
        $manager->push(new RecordingJob());

        $manager->markAsFailed($manager->pop(), new \DomainException('bad things'));

        $record = $manager->connection()->failedJobs()[0];

        $this->assertStringStartsWith('DomainException: bad things in ', $record->exception);
        $this->assertStringContainsString('Stack trace:', $record->exception);
        $this->assertSame($this->now, $record->failedAt);
    }

    public function testStatsSizeAndClearPassThrough(): void
    {
        $manager = $this->manager();
        $manager->push(new RecordingJob());
        $manager->push((new RecordingJob())->delayFor(50));

        $this->assertSame(['ready' => 1, 'delayed' => 1, 'reserved' => 0], $manager->stats());
        $this->assertSame(2, $manager->size());
        $this->assertSame(2, $manager->clear());
        $this->assertSame(0, $manager->size());
    }

    public function testRetryFailedRequeuesTheJobWithFreshAttemptsAndForgetsTheRecord(): void
    {
        $manager = $this->manager();
        $manager->push(new RecordingJob('again'));
        $reserved = $manager->pop();
        $manager->markAsFailed($reserved, new \RuntimeException('x'));

        $record = $manager->connection()->failedJobs()[0];

        $this->assertTrue($manager->retryFailed($record->id));

        $this->assertSame(0, $manager->connection()->countFailed());

        $requeued = $manager->pop();
        $job = $manager->unserializeJob($requeued->payload);

        $this->assertSame('again', $job->label);
        $this->assertSame(0, $job->attempts);
        $this->assertSame(1, $requeued->attempts);
    }

    public function testRetryFailedAcceptsARecordDirectly(): void
    {
        $manager = $this->manager();
        $manager->push(new RecordingJob());
        $manager->markAsFailed($manager->pop(), new \RuntimeException('x'));

        $this->assertTrue($manager->retryFailed($manager->connection()->failedJobs()[0]));
        $this->assertSame(1, $manager->size());
    }

    public function testRetryFailedOfAnUnknownIdIsFalse(): void
    {
        $this->assertFalse($this->manager()->retryFailed(12345));
    }

    public function testRetryFailedKeepsTheRecordWhenAUniqueJobIsAlreadyQueued(): void
    {
        $manager = $this->manager();
        $manager->push(new UniqueRecordingJob('k'));
        $manager->markAsFailed($manager->pop(), new \RuntimeException('x'));
        $manager->push(new UniqueRecordingJob('k'));

        $record = $manager->connection()->failedJobs()[0];

        $this->assertFalse($manager->retryFailed($record));
        $this->assertSame(1, $manager->connection()->countFailed(), 'the failed record must not be lost');
        $this->assertSame(1, $manager->size());
    }

    public function testRetryFailedRejectsAnUnreadablePayload(): void
    {
        $manager = $this->manager();
        $record = new FailedJobRecord(1, 'memory', 'default', 'not a serialized job', 'x', $this->now);

        $this->expectException(QueueException::class);

        $manager->retryFailed($record);
    }

    public function testUnserializingGarbageIsAQueueException(): void
    {
        $this->expectException(QueueException::class);

        $this->manager()->unserializeJob('garbage');
    }

    public function testReservedJobsAreImmutableValueObjects(): void
    {
        $reserved = new ReservedJob(1, 'q', 'p', 1, 100, 160);

        $this->expectException(\Error::class);

        $reserved->attempts = 5;
    }
}
