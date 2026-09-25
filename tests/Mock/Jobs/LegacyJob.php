<?php

namespace Doppar\Queue\Tests\Mock\Jobs;

use Doppar\Queue\Contracts\JobInterface;

/**
 * Implements only the original JobInterface: no priority(), connection(),
 * uniqueId() or backoff(). It must keep working.
 */
class LegacyJob implements JobInterface
{
    public static int $handled = 0;

    private ?string $id = null;

    public function handle(): void
    {
        self::$handled++;
    }

    public function tries(): int
    {
        return 2;
    }

    public function retryAfter(): int
    {
        return 15;
    }

    public function failed(\Throwable $exception): void
    {
    }

    public function queue(): string
    {
        return 'legacy';
    }

    public function delay(): int
    {
        return 0;
    }

    public function getJobId(): ?string
    {
        return $this->id;
    }

    public function setJobId(string $id): void
    {
        $this->id = $id;
    }

    public function getTimeout(): ?int
    {
        return null;
    }
}
