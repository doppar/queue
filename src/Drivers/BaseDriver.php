<?php

namespace Doppar\Queue\Drivers;

use Closure;
use Doppar\Queue\Contracts\QueueDriver;

abstract class BaseDriver implements QueueDriver
{
    /**
     * Default lease length in seconds
     */
    public const DEFAULT_LEASE = 90;

    /**
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * @var Closure(): int
     */
    protected Closure $clock;

    /**
     * @param array<string, mixed> $config
     * @param (Closure(): int)|null $clock
     */
    public function __construct(array $config = [], ?Closure $clock = null)
    {
        $this->config = $config;
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * Get the current unix time
     *
     * @return int
     */
    protected function now(): int
    {
        return ($this->clock)();
    }

    /**
     * Get the lease length to use for a claim
     *
     * @param int|null $leaseFor
     * @return int
     */
    protected function leaseSeconds(?int $leaseFor): int
    {
        return max(1, $leaseFor ?? (int) ($this->config['lease'] ?? self::DEFAULT_LEASE));
    }

    /**
     * Normalize a queue name or list into a list of names
     *
     * @param string|array<int, string> $queues
     * @return array<int, string>
     */
    protected function queueList(string|array $queues): array
    {
        if (is_string($queues)) {
            $queues = explode(',', $queues);
        }

        $names = [];

        foreach ($queues as $queue) {
            $queue = trim((string) $queue);

            if ($queue !== '' && !in_array($queue, $names, true)) {
                $names[] = $queue;
            }
        }

        return $names;
    }
}
