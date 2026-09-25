<?php

namespace Doppar\Queue\Tests\Drivers;

use Doppar\Queue\Contracts\QueueDriver;
use Doppar\Queue\Drivers\MemoryDriver;
use Doppar\Queue\Tests\Contract\QueueDriverContract;

class MemoryDriverTest extends QueueDriverContract
{
    protected function makeDriver(\Closure $clock): QueueDriver
    {
        return new MemoryDriver(['lease' => self::LEASE], $clock);
    }

    public function testEachDriverInstanceHasItsOwnJobs(): void
    {
        $this->push('a');

        $other = new MemoryDriver(['lease' => self::LEASE], fn(): int => $this->now);

        $this->assertNull($other->pop('default'));
    }
}
