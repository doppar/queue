<?php

namespace Doppar\Queue\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Queueable
{
    /**
     * @param int|null $tries
     * @param int|null $retryAfter
     * @param int|null $delayFor
     * @param string|null $onQueue
     * @param int|null $timeout
     * @param int|null $priority
     * @param string|null $onConnection
     * @param int|array<int, int>|null $backoff
     */
    public function __construct(
        public ?int $tries = null,
        public ?int $retryAfter = null,
        public ?int $delayFor = null,
        public ?string $onQueue = null,
        public ?int $timeout = null,
        public ?int $priority = null,
        public ?string $onConnection = null,
        public int|array|null $backoff = null
    ) {}
}
