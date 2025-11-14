<?php

namespace Doppar\Queue\Tests\Mock\Jobs;

use Doppar\Queue\Job;

class TestReportJob extends Job
{
    public $tries = 2;
    public $retryAfter = 300;
    public $reportType;

    public function __construct(string $reportType)
    {
        $this->reportType = $reportType;
    }

    public function handle(): void
    {
        if (empty($this->reportType)) {
            throw new \RuntimeException('Invalid report type');
        }
    }
}
