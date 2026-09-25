<?php

namespace Doppar\Queue\Tests\Unit;

use Doppar\Queue\Tests\Mock\Jobs\RecordingJob;
use PHPUnit\Framework\TestCase;

class JobSerializationStaticStateTest extends TestCase
{
    protected function setUp(): void
    {
        RecordingJob::reset();
    }

    protected function tearDown(): void
    {
        RecordingJob::reset();
    }

    public function testStaticPropertiesAreNotWrittenIntoThePayload(): void
    {
        RecordingJob::$handled = ['leaked-secret-state'];

        $payload = serialize(new RecordingJob('x'));

        $this->assertStringNotContainsString('leaked-secret-state', $payload);
        $this->assertStringNotContainsString('handled', $payload);
    }

    public function testUnserializingAJobLeavesTheLiveStaticStateAlone(): void
    {
        $payload = serialize(new RecordingJob('x'));

        RecordingJob::$handled = ['first', 'second'];

        unserialize($payload);

        $this->assertSame(['first', 'second'], RecordingJob::$handled);
    }

    public function testInstanceStateStillRoundTrips(): void
    {
        $job = new RecordingJob('kept');
        $job->tries = 7;
        $job->onQueue('emails');

        $restored = unserialize(serialize($job));

        $this->assertSame('kept', $restored->label);
        $this->assertSame(7, $restored->tries);
        $this->assertSame('emails', $restored->queue());
    }

    public function testAnOldPayloadThatCarriesStaticStateDoesNotOverwriteTheLiveValue(): void
    {
        // What the previous version produced: the static property serialized as if
        // it were an instance property.
        $legacy = 'O:' . strlen(RecordingJob::class) . ':"' . RecordingJob::class . '":2:{'
            . 's:5:"label";s:3:"old";'
            . 's:7:"handled";a:1:{i:0;s:5:"stale";}}';

        RecordingJob::$handled = ['live'];

        $job = unserialize($legacy);

        $this->assertSame('old', $job->label);
        $this->assertSame(['live'], RecordingJob::$handled);
    }
}
