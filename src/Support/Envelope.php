<?php

namespace Doppar\Queue\Support;

use Doppar\Queue\Contracts\QueueDriver;

final readonly class Envelope
{
    public int $priority;

    /**
     * @param string $id
     * @param string $queue
     * @param string $payload
     * @param int $availableAt
     * @param int $priority
     * @param string|null $uniqueKey
     * @param int $createdAt
     */
    public function __construct(
        public string $id,
        public string $queue,
        public string $payload,
        public int $availableAt,
        int $priority = 0,
        public ?string $uniqueKey = null,
        public int $createdAt = 0,
    ) {
        $this->priority = max(QueueDriver::PRIORITY_MIN, min(QueueDriver::PRIORITY_MAX, $priority));
    }
}
