<?php

namespace Doppar\Queue;

use Closure;
use Doppar\Queue\Contracts\JobInterface;
use Doppar\Queue\Contracts\QueueDriver;
use Doppar\Queue\Drivers\DatabaseDriver;
use Doppar\Queue\Drivers\MemoryDriver;
use Doppar\Queue\Drivers\RedisDriver;
use Doppar\Queue\Exceptions\QueueException;
use Doppar\Queue\Support\Envelope;
use Doppar\Queue\Support\FailedJobRecord;
use Doppar\Queue\Support\ReservedJob;

class QueueManager
{
    /**
     * The default queue name.
     *
     * @var string
     */
    protected $defaultQueue = 'default';

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $config;

    /**
     * @var Closure(): int
     */
    protected Closure $clock;

    /**
     * Resolved connections
     *
     * @var array<string, QueueDriver>
     */
    protected array $connections = [];

    /**
     * Custom driver factories registered with extend()
     *
     * @var array<string, Closure(array<string, mixed>, Closure): QueueDriver>
     */
    protected array $customDrivers = [];

    /**
     * @param array<string, mixed>|null $config
     * @param Closure(): int|null
     */
    public function __construct(?array $config = null, ?Closure $clock = null)
    {
        $this->config = $config;
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * Get a queue driver by connection name, or the default connection.
     *
     * @param string|null $name
     * @return QueueDriver
     * @throws QueueException
     */
    public function connection(?string $name = null): QueueDriver
    {
        $name ??= $this->getDefaultConnection();

        return $this->connections[$name] ??= $this->makeDriver($name);
    }

    /**
     * Get the default connection name.
     *
     * @return string
     */
    public function getDefaultConnection(): string
    {
        return (string) ($this->config()['default'] ?? 'database');
    }

    /**
     * Register a factory for a custom driver name
     *
     * @param string $driver
     * @param Closure(array<string, mixed>, Closure): QueueDriver $factory
     * @return void
     */
    public function extend(string $driver, Closure $factory): void
    {
        $this->customDrivers[$driver] = $factory;
        $this->connections = [];
    }

    /**
     * Drop a resolved connection (or all of them) so it is rebuilt on next use.
     *
     * @param string|null $name
     * @return void
     */
    public function purge(?string $name = null): void
    {
        if ($name === null) {
            $this->connections = [];

            return;
        }

        unset($this->connections[$name]);
    }

    /**
     * Push a job onto the queue.
     *
     * @param JobInterface $job
     * @param string|null $connection
     * @return string|null Job ID, or null when refused as a duplicate of a unique job
     * @throws QueueException
     */
    public function push(JobInterface $job, ?string $connection = null): ?string
    {
        try {
            $envelope = $this->envelopeFor($job);
            $stored = $this->connection($this->connectionFor($job, $connection))->push($envelope);

            return $stored ? $envelope->id : null;
        } catch (\Throwable $e) {
            throw new QueueException("Failed to push job to queue: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Push many jobs with as few round trips as the driver allows.
     *
     * @param array<int, JobInterface> $jobs
     * @param string|null $connection
     * @return int
     * @throws QueueException
     */
    public function pushMany(array $jobs, ?string $connection = null): int
    {
        try {
            $grouped = [];

            foreach ($jobs as $job) {
                $grouped[$this->connectionFor($job, $connection) ?? $this->getDefaultConnection()][] = $this->envelopeFor($job);
            }

            $stored = 0;

            foreach ($grouped as $name => $envelopes) {
                $stored += $this->connection($name)->pushMany($envelopes);
            }

            return $stored;
        } catch (\Throwable $e) {
            throw new QueueException("Failed to push jobs to queue: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Claim the next job. Accepts a queue name, a comma separated list, or an
     * array; queues are tried in the order given.
     *
     * @param string|array<int, string> $queue
     * @param string|null $connection
     * @return ReservedJob|null
     */
    public function pop(string|array $queue = 'default', ?string $connection = null): ?ReservedJob
    {
        return $this->connection($connection)->pop($queue);
    }

    /**
     * Delete a job from the queue.
     *
     * @param ReservedJob $queueJob
     * @param string|null $connection
     * @return bool
     */
    public function delete(ReservedJob $queueJob, ?string $connection = null): bool
    {
        return $this->connection($connection)->delete($queueJob);
    }

    /**
     * Release a job back to the queue.
     *
     * @param ReservedJob $queueJob
     * @param int $delay
     * @param string|null $connection
     * @return bool
     */
    public function release(ReservedJob $queueJob, int $delay = 0, ?string $connection = null): bool
    {
        return $this->connection($connection)->release($queueJob, $delay);
    }

    /**
     * Extend the lease of a job that is still being worked on.
     *
     * @param ReservedJob $queueJob
     * @param int $seconds
     * @param string|null $connection
     * @return bool
     */
    public function extendLease(ReservedJob $queueJob, int $seconds, ?string $connection = null): bool
    {
        return $this->connection($connection)->extend($queueJob, $seconds);
    }

    /**
     * Move a job to the failed store.
     *
     * @param ReservedJob $queueJob
     * @param \Throwable $exception
     * @param string|null $connection
     * @return void
     */
    public function markAsFailed(ReservedJob $queueJob, \Throwable $exception, ?string $connection = null): void
    {
        $this->connection($connection)->fail($queueJob, $this->formatException($exception));
    }

    /**
     * Get the count of jobs in a queue that are not reserved.
     *
     * @param string $queue
     * @param string|null $connection
     * @return int
     */
    public function size(string $queue = 'default', ?string $connection = null): int
    {
        return $this->connection($connection)->size($queue);
    }

    /**
     * Break a queue down into ready, delayed and reserved jobs.
     *
     * @param string $queue
     * @param string|null $connection
     * @return array{ready: int, delayed: int, reserved: int}
     */
    public function stats(string $queue = 'default', ?string $connection = null): array
    {
        return $this->connection($connection)->stats($queue);
    }

    /**
     * Clear all jobs from a queue.
     *
     * @param string $queue
     * @param string|null $connection
     * @return int Number of jobs deleted
     */
    public function clear(string $queue = 'default', ?string $connection = null): int
    {
        return $this->connection($connection)->clear($queue);
    }

    /**
     * Put a failed job back on its queue and remove it from the failed store.
     *
     * @param FailedJobRecord|string|int $failed
     * @param string|null $connection
     * @return bool
     */
    public function retryFailed(FailedJobRecord|string|int $failed, ?string $connection = null): bool
    {
        $driver = $this->connection($connection);
        $record = $failed instanceof FailedJobRecord ? $failed : $driver->findFailed($failed);

        if ($record === null) {
            return false;
        }

        $job = $this->unserializeJob($record->payload);

        if ($job instanceof Job) {
            $job->attempts = 0;
        }

        if ($this->push($job, $connection) === null) {
            return false;
        }

        return $driver->forgetFailed($record->id);
    }

    /**
     * Create a payload string from the given job.
     *
     * @param JobInterface $job
     * @return string
     */
    protected function createPayload(JobInterface $job): string
    {
        return serialize([
            'job' => $job,
            'data' => [
                'jobId' => $job->getJobId(),
                'queue' => $job->queue(),
                'tries' => $job->tries(),
                'retryAfter' => $job->retryAfter(),
            ],
        ]);
    }

    /**
     * Unserialize the job from payload.
     *
     * @param string $payload
     * @return JobInterface
     * @throws QueueException
     */
    public function unserializeJob(string $payload): JobInterface
    {
        try {
            $data = @unserialize($payload);
        } catch (\Throwable $e) {
            throw new QueueException("Failed to unserialize job: " . $e->getMessage(), 0, $e);
        }

        // A payload that is corrupt, is not a job, or names a class that no
        // longer exists yields something other than a JobInterface.
        if (!is_array($data) || !($data['job'] ?? null) instanceof JobInterface) {
            throw new QueueException('Failed to unserialize job: the payload does not contain a valid job.');
        }

        return $data['job'];
    }

    /**
     * Build the driver-facing envelope for a job, stamping it with a job id.
     *
     * @param JobInterface $job
     * @return Envelope
     */
    protected function envelopeFor(JobInterface $job): Envelope
    {
        $id = $this->generateJobId();
        $job->setJobId($id);

        $now = ($this->clock)();

        return new Envelope(
            $id,
            $job->queue(),
            $this->createPayload($job),
            $now + $job->delay(),
            (int) $this->optional($job, 'priority', 0),
            $this->uniqueKeyFor($job),
            $now
        );
    }

    /**
     * Get the connection a job asks for, or the given fallback.
     *
     * @param JobInterface $job
     * @param string|null $fallback
     * @return string|null
     */
    protected function connectionFor(JobInterface $job, ?string $fallback): ?string
    {
        return $this->optional($job, 'connection', null) ?? $fallback;
    }

    /**
     * Derive the uniqueness key of a job, or null when it is not unique.
     *
     * @param JobInterface $job
     * @return string|null
     */
    protected function uniqueKeyFor(JobInterface $job): ?string
    {
        $id = $this->optional($job, 'uniqueId', null);

        if ($id === null) {
            return null;
        }

        $key = $job::class . ':' . $id;

        // Keeps the key inside common index length limits.
        return strlen($key) > 191 ? $job::class . ':' . sha1($key) : $key;
    }

    /**
     * Read an optional job setting
     *
     * @param JobInterface $job
     * @param string $method
     * @param mixed $default
     * @return mixed
     */
    protected function optional(JobInterface $job, string $method, mixed $default): mixed
    {
        return method_exists($job, $method) ? $job->{$method}() : $default;
    }

    /**
     * Generate a unique job ID.
     *
     * @return string
     */
    protected function generateJobId(): string
    {
        return uniqid('job_', true);
    }

    /**
     * Format exception for storage.
     *
     * @param \Throwable $exception
     * @return string
     */
    protected function formatException(\Throwable $exception): string
    {
        return sprintf(
            "%s: %s in %s:%d\nStack trace:\n%s",
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        );
    }

    /**
     * Set the default queue name.
     *
     * @param string $queue
     * @return void
     */
    public function setDefaultQueue(string $queue): void
    {
        $this->defaultQueue = $queue;
    }

    /**
     * Get the default queue name.
     *
     * @return string
     */
    public function getDefaultQueue(): string
    {
        return $this->defaultQueue;
    }

    /**
     * Build the driver for a named connection.
     *
     * @param string $name
     * @return QueueDriver
     * @throws QueueException
     */
    protected function makeDriver(string $name): QueueDriver
    {
        $connection = $this->config()['connections'][$name] ?? null;

        if ($connection === null) {
            throw new QueueException("Queue connection [{$name}] is not configured.");
        }

        $driver = $connection['driver'] ?? $name;
        $connection['name'] = $name;

        if (isset($this->customDrivers[$driver])) {
            return ($this->customDrivers[$driver])($connection, $this->clock);
        }

        return match ($driver) {
            'database' => new DatabaseDriver($connection, $this->clock),
            'redis' => new RedisDriver($connection, null, $this->clock),
            'memory', 'array' => new MemoryDriver($connection, $this->clock),
            default => throw new QueueException("Queue driver [{$driver}] is not supported."),
        };
    }

    /**
     * Resolve the queue config, filling gaps from the built-in defaults
     *
     * @return array<string, mixed>
     */
    protected function config(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $defaults = [
            'default' => 'database',
            'connections' => [
                'database' => ['driver' => 'database'],
                'redis' => ['driver' => 'redis'],
                'memory' => ['driver' => 'memory'],
            ],
        ];

        try {
            $configured = config('queue');
        } catch (\Throwable) {
            $configured = null;
        }

        return $this->config = is_array($configured)
            ? array_replace_recursive($defaults, $configured)
            : $defaults;
    }
}
