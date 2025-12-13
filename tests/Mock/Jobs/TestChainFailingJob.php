<?php

namespace Doppar\Queue\Tests\Mock\Jobs;

use Doppar\Queue\Job;

class TestChainFailingJob extends Job
{
    public static $executed = [];
    public string $name;

    public function __construct(string $name = 'FailingJob')
    {
        $this->name = $name;
    }

    public function handle(): void
    {
        self::$executed[] = $this->name;
        throw new \RuntimeException('Intentional chain failure');
    }

    public static function reset(): void
    {
        self::$executed = [];
    }
}