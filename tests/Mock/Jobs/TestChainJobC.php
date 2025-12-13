<?php 

namespace Doppar\Queue\Tests\Mock\Jobs;

use Doppar\Queue\Job;

class TestChainJobC extends Job
{
    public static $executed = [];
    public string $name;

    public function __construct(string $name = 'JobC')
    {
        $this->name = $name;
    }

    public function handle(): void
    {
        self::$executed[] = $this->name;
    }

    public static function reset(): void
    {
        self::$executed = [];
    }
}
