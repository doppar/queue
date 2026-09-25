<?php

namespace Doppar\Queue\Tests\Unit;

use Doppar\Queue\QueueManager;
use Doppar\Queue\Tests\Mock\Commands\SpyFailedCommand;
use Doppar\Queue\Tests\Mock\Commands\SpyFlushCommand;
use Doppar\Queue\Tests\Mock\Commands\SpyMonitorCommand;
use Doppar\Queue\Tests\Mock\Commands\SpyRetryCommand;
use Doppar\Queue\Tests\Mock\Commands\SpyRunCommand;
use Doppar\Queue\Tests\Mock\Jobs\RecordingJob;
use Doppar\Queue\Tests\Mock\Jobs\UniqueRecordingJob;
use Doppar\Queue\Tests\Mock\MockContainer;
use Phaseolies\DI\Container;
use PHPUnit\Framework\TestCase;

class QueueCommandsTest extends TestCase
{
    private int $now = 1_800_000_000;

    private QueueManager $manager;

    protected function setUp(): void
    {
        $this->now = 1_800_000_000;
        RecordingJob::reset();

        $this->manager = new QueueManager([
            'default' => 'memory',
            'connections' => [
                'memory' => ['driver' => 'memory', 'lease' => 60],
                'other' => ['driver' => 'memory', 'lease' => 60],
            ],
        ], fn(): int => $this->now);

        $container = new MockContainer();
        Container::setInstance($container);
        $container->singleton(QueueManager::class, fn() => $this->manager);
        $container->alias(QueueManager::class, 'queue.worker');
    }

    protected function tearDown(): void
    {
        RecordingJob::reset();
        Container::forgetInstance();
    }

    /**
     * Fail a job on the given connection and return its failed record id.
     */
    private function failJob(RecordingJob $job, ?string $connection = null): int|string
    {
        $this->manager->push($job, $connection);
        $this->manager->markAsFailed($this->manager->pop($job->queue(), $connection), new \RuntimeException('boom'), $connection);

        return $this->manager->connection($connection)->failedJobs()[0]->id;
    }

    // ------------------------------------------------------------------
    // queue:retry
    // ------------------------------------------------------------------

    public function testRetryRequeuesEveryFailedJob(): void
    {
        $this->failJob(new RecordingJob('one'));
        $this->failJob(new RecordingJob('two'));

        $command = (new SpyRetryCommand())->withOptions([]);

        $this->assertSame(0, $command->handle());
        $this->assertSame(2, $this->manager->size());
        $this->assertSame(0, $this->manager->connection()->countFailed());
        $this->assertCount(2, array_filter($command->lines, fn($l) => str_contains($l, 'Retried job [')));
    }

    public function testRetryByIdRequeuesOnlyThatJob(): void
    {
        $this->failJob(new RecordingJob('keep-failed'));
        $target = $this->failJob(new RecordingJob('retry-me'));

        $command = (new SpyRetryCommand())->withOptions(['id' => (string) $target]);

        $this->assertSame(0, $command->handle());
        $this->assertSame(1, $this->manager->size());
        $this->assertSame(1, $this->manager->connection()->countFailed());
        $this->assertSame('retry-me', $this->manager->unserializeJob($this->manager->pop()->payload)->label);
    }

    public function testRetryOfAnUnknownIdFails(): void
    {
        $command = (new SpyRetryCommand())->withOptions(['id' => '999']);

        $this->assertSame(1, $command->handle());
        $this->assertSame(['Failed job with ID 999 not found.'], $command->errors);
    }

    public function testRetryExplainsWhenAUniqueJobIsAlreadyQueued(): void
    {
        $this->manager->push(new UniqueRecordingJob('k'));
        $this->manager->markAsFailed($this->manager->pop(), new \RuntimeException('x'));
        $this->manager->push(new UniqueRecordingJob('k'));
        $id = $this->manager->connection()->failedJobs()[0]->id;

        $command = (new SpyRetryCommand())->withOptions(['id' => (string) $id]);

        $this->assertSame(1, $command->handle());
        $this->assertStringContainsString('unique job with the same key is already queued', $command->errors[0]);
        $this->assertSame(1, $this->manager->connection()->countFailed());
    }

    public function testRetryReportsAnUnreadablePayloadAndKeepsGoing(): void
    {
        $this->failJob(new RecordingJob('good'));
        $this->manager->connection()->failedJobs();
        $reflection = new \ReflectionProperty($this->manager->connection(), 'failed');
        $failed = $reflection->getValue($this->manager->connection());
        $failed[99] = new \Doppar\Queue\Support\FailedJobRecord(99, 'memory', 'default', 'garbage', 'x', $this->now);
        $reflection->setValue($this->manager->connection(), $failed);

        $command = (new SpyRetryCommand())->withOptions([]);

        $this->assertSame(0, $command->handle());
        $this->assertCount(1, $command->errors);
        $this->assertStringContainsString('Failed to retry job ID 99', $command->errors[0]);
        $this->assertSame(1, $this->manager->size(), 'the readable job was still requeued');
    }

    public function testRetryCanTargetAnotherConnection(): void
    {
        $this->failJob(new RecordingJob('remote'), 'other');

        $command = (new SpyRetryCommand())->withOptions(['connection' => 'other']);

        $this->assertSame(0, $command->handle());
        $this->assertSame(1, $this->manager->size('default', 'other'));
        $this->assertSame(0, $this->manager->size('default', 'memory'));
    }

    // ------------------------------------------------------------------
    // queue:failed and queue:flush
    // ------------------------------------------------------------------

    public function testFailedSaysSoWhenThereAreNoFailedJobs(): void
    {
        $command = (new SpyFailedCommand())->withOptions([]);

        $this->assertSame(0, $command->handle());
        $this->assertSame(['No failed jobs found.'], $command->lines);
        $this->assertSame([], $command->tables);
    }

    public function testFailedListsEachJobWithItsClassQueueAndTime(): void
    {
        $this->failJob((new RecordingJob('x'))->onQueue('emails'));

        $command = (new SpyFailedCommand())->withOptions([]);
        $command->handle();

        $table = $command->tables[0];

        $this->assertSame(['ID', 'Job', 'Queue', 'Failed At'], $table->headers);
        $this->assertCount(1, $table->rows);
        $this->assertSame(RecordingJob::class, $table->rows[0][1]);
        $this->assertSame('emails', $table->rows[0][2]);
        $this->assertSame(date('Y-m-d H:i:s', $this->now), $table->rows[0][3]);
        $this->assertTrue($table->rendered);
    }

    public function testFailedToleratesAnUnreadablePayload(): void
    {
        $this->failJob(new RecordingJob('x'));
        $reflection = new \ReflectionProperty($this->manager->connection(), 'failed');
        $failed = $reflection->getValue($this->manager->connection());
        $failed[5] = new \Doppar\Queue\Support\FailedJobRecord(5, 'memory', 'default', 'garbage', 'x', $this->now);
        $reflection->setValue($this->manager->connection(), $failed);

        $command = (new SpyFailedCommand())->withOptions([]);

        $this->assertSame(0, $command->handle());
        $this->assertCount(2, $command->tables[0]->rows);
        $this->assertContains(null, array_column($command->tables[0]->rows, 1));
    }

    public function testFlushDeletesEveryFailedJob(): void
    {
        $this->failJob(new RecordingJob('a'));
        $this->failJob(new RecordingJob('b'));

        $command = (new SpyFlushCommand())->withOptions([]);

        $this->assertSame(0, $command->handle());
        $this->assertSame(0, $this->manager->connection()->countFailed());
        $this->assertStringContainsString('2 failed job(s) deleted', $command->lines[0]);
    }

    public function testFlushByIdDeletesOnlyThatJob(): void
    {
        $this->failJob(new RecordingJob('keep'));
        $target = $this->failJob(new RecordingJob('drop'));

        $command = (new SpyFlushCommand())->withOptions(['id' => (string) $target]);

        $this->assertSame(0, $command->handle());
        $this->assertSame(1, $this->manager->connection()->countFailed());
        $this->assertNull($this->manager->connection()->findFailed($target));
    }

    public function testFlushOfAnUnknownIdFails(): void
    {
        $command = (new SpyFlushCommand())->withOptions(['id' => '404']);

        $this->assertSame(1, $command->handle());
        $this->assertSame(['Failed job with ID 404 not found.'], $command->errors);
    }

    // ------------------------------------------------------------------
    // queue:monitor
    // ------------------------------------------------------------------

    public function testMonitorShowsReadyDelayedAndProcessingPerQueue(): void
    {
        $this->manager->push((new RecordingJob())->onQueue('emails'));
        $this->manager->push((new RecordingJob())->onQueue('emails'));
        $this->manager->push((new RecordingJob())->onQueue('emails')->delayFor(100));
        $this->manager->pop('emails');
        $this->manager->push((new RecordingJob())->onQueue('reports'));
        $this->failJob(new RecordingJob('f'));

        $command = (new SpyMonitorCommand())->withOptions([]);

        $this->assertSame(0, $command->handle());

        [$queues, $failed] = $command->tables;

        $this->assertSame(['Queue', 'Ready', 'Delayed', 'Processing'], $queues->headers);
        $this->assertSame([['emails', 1, 1, 1], ['reports', 1, 0, 0]], $queues->rows);
        $this->assertSame([['Failed Jobs', 1]], $failed->rows);
    }

    public function testMonitorWithNothingQueuedStillRendersTheSummary(): void
    {
        $command = (new SpyMonitorCommand())->withOptions([]);

        $this->assertSame(0, $command->handle());
        $this->assertSame([], $command->tables[0]->rows);
        $this->assertSame([['Failed Jobs', 0]], $command->tables[1]->rows);
    }

    // ------------------------------------------------------------------
    // queue:run
    // ------------------------------------------------------------------

    public function testRunProcessesJobsFromTheChosenConnectionUpToTheLimit(): void
    {
        $this->manager->push(new RecordingJob('remote-1'), 'other');
        $this->manager->push(new RecordingJob('remote-2'), 'other');
        $this->manager->push(new RecordingJob('local'), 'memory');

        $command = (new SpyRunCommand($this->manager))->withOptions([
            'queue' => 'default',
            'connection' => 'other',
            'sleep' => '0',
            'memory' => '1024',
            'timeout' => 0,
            'limit' => '2',
        ]);

        ob_start();
        $status = $command->handle();
        ob_end_clean();

        $this->assertSame(0, $status);
        $this->assertSame(['remote-1', 'remote-2'], RecordingJob::$handled);
        $this->assertSame(1, $this->manager->size('default', 'memory'), 'the other connection was left alone');
        $this->assertStringContainsString('connection: other', implode("\n", $command->lines));
    }
}
