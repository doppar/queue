<?php

namespace Doppar\Queue\Tests\Unit;

use Doppar\Queue\Contracts\QueueDriver;
use Doppar\Queue\Support\Envelope;
use Doppar\Queue\Tests\Mock\Jobs\QueueableAttributeJob;
use Doppar\Queue\Tests\Mock\Jobs\RecordingJob;
use Doppar\Queue\Tests\Mock\Jobs\UniqueRecordingJob;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JobSettingsTest extends TestCase
{
    public function testAJobHasNeutralDefaults(): void
    {
        $job = new RecordingJob();

        $this->assertSame(0, $job->priority());
        $this->assertNull($job->connection());
        $this->assertNull($job->uniqueId());
        $this->assertNull($job->backoff());
        $this->assertSame('default', $job->queue());
    }

    public function testPriorityAndConnectionAreFluent(): void
    {
        $job = new RecordingJob();

        $this->assertSame($job, $job->withPriority(30)->onConnection('redis')->onQueue('emails'));
        $this->assertSame(30, $job->priority());
        $this->assertSame('redis', $job->connection());
        $this->assertSame('emails', $job->queue());
    }

    public function testAJobDecidesItsOwnUniqueness(): void
    {
        $this->assertSame('report-7', (new UniqueRecordingJob('report-7'))->uniqueId());
    }

    public function testTheQueueableAttributeSetsPriorityConnectionAndBackoff(): void
    {
        $job = new QueueableAttributeJob();

        (new \ReflectionMethod($job, 'applyQueueableAttributes'))->invoke($job);

        $this->assertSame(4, $job->tries());
        $this->assertSame('reports', $job->queue());
        $this->assertSame(25, $job->priority());
        $this->assertSame('memory', $job->connection());
        $this->assertSame([5, 15], $job->backoff());
    }

    public function testAttributeValuesLeaveUnsetOptionsAlone(): void
    {
        $job = new RecordingJob();
        $job->withPriority(9);

        $this->assertSame(9, $job->priority());
    }

    public static function priorities(): array
    {
        return [
            'in range' => [10, 10],
            'zero' => [0, 0],
            'max' => [QueueDriver::PRIORITY_MAX, QueueDriver::PRIORITY_MAX],
            'min' => [QueueDriver::PRIORITY_MIN, QueueDriver::PRIORITY_MIN],
            'above max' => [101, 100],
            'far above max' => [PHP_INT_MAX, 100],
            'below min' => [-101, -100],
            'far below min' => [PHP_INT_MIN, -100],
        ];
    }

    #[DataProvider('priorities')]
    public function testAnEnvelopeClampsPriorityToTheSupportedRange(int $given, int $expected): void
    {
        $envelope = new Envelope('id', 'default', 'payload', 100, $given);

        $this->assertSame($expected, $envelope->priority);
    }

    public function testAnEnvelopeIsImmutable(): void
    {
        $envelope = new Envelope('id', 'default', 'payload', 100);

        $this->expectException(\Error::class);

        $envelope->queue = 'other';
    }
}
