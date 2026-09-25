<?php

namespace Doppar\Queue\Support;

final readonly class FailedJobRecord
{
    /**
     * @param string|int $id
     * @param string $connection
     * @param string $queue
     * @param string $payload
     * @param string $exception
     * @param int $failedAt Unix time
     */
    public function __construct(
        public string|int $id,
        public string $connection,
        public string $queue,
        public string $payload,
        public string $exception,
        public int $failedAt,
    ) {}
}
