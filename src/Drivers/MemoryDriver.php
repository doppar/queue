<?php

namespace Doppar\Queue\Drivers;

use Doppar\Queue\Support\Envelope;
use Doppar\Queue\Support\FailedJobRecord;
use Doppar\Queue\Support\ReservedJob;

class MemoryDriver extends BaseDriver
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $jobs = [];

    /**
     * @var array<string, string> unique key => job id
     */
    private array $unique = [];

    /**
     * @var array<int, FailedJobRecord>
     */
    private array $failed = [];

    private int $sequence = 0;

    private int $failedSequence = 0;

    /**
     * @inheritDoc
     */
    public function push(Envelope $envelope): bool
    {
        if ($envelope->uniqueKey !== null && isset($this->unique[$envelope->uniqueKey])) {
            return false;
        }

        $this->jobs[$envelope->id] = [
            'queue' => $envelope->queue,
            'payload' => $envelope->payload,
            'attempts' => 0,
            'priority' => $envelope->priority,
            'available_at' => $envelope->availableAt,
            'reserved_at' => null,
            'lease_expires_at' => null,
            'unique' => $envelope->uniqueKey,
            'seq' => ++$this->sequence,
        ];

        if ($envelope->uniqueKey !== null) {
            $this->unique[$envelope->uniqueKey] = $envelope->id;
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function pushMany(array $envelopes): int
    {
        $stored = 0;

        foreach ($envelopes as $envelope) {
            $stored += $this->push($envelope) ? 1 : 0;
        }

        return $stored;
    }

    /**
     * @inheritDoc
     */
    public function pop(string|array $queues, ?int $leaseFor = null): ?ReservedJob
    {
        $now = $this->now();
        $lease = $this->leaseSeconds($leaseFor);

        foreach ($this->queueList($queues) as $queue) {
            $candidates = array_filter(
                $this->jobs,
                fn(array $job): bool => $job['queue'] === $queue
                    && $job['available_at'] <= $now
                    && $this->isClaimable($job, $now)
            );

            if ($candidates === []) {
                continue;
            }

            uasort($candidates, fn(array $a, array $b): int => [$b['priority'], $a['seq']] <=> [$a['priority'], $b['seq']]);

            $id = (string) array_key_first($candidates);

            $this->jobs[$id]['attempts']++;
            $this->jobs[$id]['reserved_at'] = $now;
            $this->jobs[$id]['lease_expires_at'] = $now + $lease;

            return new ReservedJob(
                $id,
                $queue,
                $this->jobs[$id]['payload'],
                $this->jobs[$id]['attempts'],
                $now,
                $now + $lease
            );
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function delete(ReservedJob $job): bool
    {
        if (!$this->holds($job)) {
            return false;
        }

        $this->forget((string) $job->id);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function release(ReservedJob $job, int $delay = 0): bool
    {
        if (!$this->holds($job)) {
            return false;
        }

        $id = (string) $job->id;

        $this->jobs[$id]['reserved_at'] = null;
        $this->jobs[$id]['lease_expires_at'] = null;
        $this->jobs[$id]['available_at'] = $this->now() + max(0, $delay);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function extend(ReservedJob $job, int $seconds): bool
    {
        if (!$this->holds($job)) {
            return false;
        }

        $this->jobs[(string) $job->id]['lease_expires_at'] = $this->now() + $seconds;

        return true;
    }

    /**
     * @inheritDoc
     */
    public function fail(ReservedJob $job, string $exception): bool
    {
        if (!$this->holds($job)) {
            return false;
        }

        $id = ++$this->failedSequence;

        $this->failed[$id] = new FailedJobRecord(
            $id,
            $this->config['name'] ?? 'memory',
            $this->jobs[(string) $job->id]['queue'],
            $this->jobs[(string) $job->id]['payload'],
            $exception,
            $this->now()
        );

        $this->forget((string) $job->id);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function size(string $queue): int
    {
        $stats = $this->stats($queue);

        return $stats['ready'] + $stats['delayed'];
    }

    /**
     * @inheritDoc
     */
    public function stats(string $queue): array
    {
        $now = $this->now();
        $stats = ['ready' => 0, 'delayed' => 0, 'reserved' => 0];

        foreach ($this->jobs as $job) {
            if ($job['queue'] !== $queue) {
                continue;
            }

            if (!$this->isClaimable($job, $now)) {
                $stats['reserved']++;
            } elseif ($job['available_at'] > $now) {
                $stats['delayed']++;
            } else {
                $stats['ready']++;
            }
        }

        return $stats;
    }

    /**
     * @inheritDoc
     */
    public function queues(): array
    {
        $names = array_values(array_unique(array_column($this->jobs, 'queue')));
        sort($names);

        return $names;
    }

    /**
     * @inheritDoc
     */
    public function clear(string $queue): int
    {
        $cleared = 0;

        foreach ($this->jobs as $id => $job) {
            if ($job['queue'] === $queue) {
                $this->forget((string) $id);
                $cleared++;
            }
        }

        return $cleared;
    }

    /**
     * @inheritDoc
     */
    public function failedJobs(): array
    {
        return array_reverse(array_values($this->failed));
    }

    /**
     * @inheritDoc
     */
    public function findFailed(string|int $id): ?FailedJobRecord
    {
        return $this->failed[(int) $id] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function forgetFailed(string|int $id): bool
    {
        if (!isset($this->failed[(int) $id])) {
            return false;
        }

        unset($this->failed[(int) $id]);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function flushFailed(): int
    {
        $count = count($this->failed);
        $this->failed = [];

        return $count;
    }

    /**
     * @inheritDoc
     */
    public function countFailed(): int
    {
        return count($this->failed);
    }

    /**
     * A job can be claimed when it is unreserved or its lease has run out
     *
     * @param array<string, mixed> $job
     * @param int $now
     * @return bool
     */
    private function isClaimable(array $job, int $now): bool
    {
        return $job['reserved_at'] === null || $job['lease_expires_at'] <= $now;
    }

    /**
     * Whether the given reservation is still the live one for its job
     *
     * @param ReservedJob $job
     * @return bool
     */
    private function holds(ReservedJob $job): bool
    {
        $id = (string) $job->id;

        return isset($this->jobs[$id])
            && $this->jobs[$id]['reserved_at'] !== null
            && $this->jobs[$id]['attempts'] === $job->attempts;
    }

    private function forget(string $id): void
    {
        $key = $this->jobs[$id]['unique'] ?? null;

        if ($key !== null && ($this->unique[$key] ?? null) === $id) {
            unset($this->unique[$key]);
        }

        unset($this->jobs[$id]);
    }
}
