<?php

namespace Doppar\Queue\Support;

final readonly class ReservedJob
{
    /**
     * @param string|int $id
     * @param string $queue
     * @param string $payload
     * @param int $attempts
     * @param int $reservedAt
     * @param int $leaseExpiresAt
     */
    public function __construct(
        public string|int $id,
        public string $queue,
        public string $payload,
        public int $attempts,
        public int $reservedAt,
        public int $leaseExpiresAt = 0,
    ) {}
}
