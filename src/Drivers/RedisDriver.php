<?php

namespace Doppar\Queue\Drivers;

use Closure;
use Doppar\Queue\Support\Envelope;
use Doppar\Queue\Support\FailedJobRecord;
use Doppar\Queue\Support\ReservedJob;
use Predis\Client;
use Predis\Response\ServerException;

class RedisDriver extends BaseDriver
{
    /**
     * Ready jobs are ordered by (PRIORITY_MAX - priority) * SCORE_BAND + arrival
     * sequence. The band keeps the sequence of one priority from overlapping the
     * next while staying exactly representable in a double.
     */
    private const SCORE_BAND = 1000000000000;

    /**
     * Delayed and expired jobs promoted per pop, bounding the work of one call
     */
    private const PROMOTE_LIMIT = 100;

    /**
     * Jobs removed per clear() script call
     */
    private const CLEAR_BATCH = 1000;

    private Client $client;

    private string $prefix;

    /**
     * @var array<string, string> script name => sha1
     */
    private array $shas = [];

    /**
     * @param array<string, mixed> $config
     * @param Client|null $client
     * @param Closure(): int|null $clock
     */
    public function __construct(array $config = [], ?Client $client = null, ?Closure $clock = null)
    {
        parent::__construct($config, $clock);

        if ($client === null && !class_exists(Client::class)) {
            throw new \RuntimeException(
                'The "redis" queue driver requires predis/predis. Install it with: composer require predis/predis'
            );
        }

        $this->client = $client ?? new Client(
            $config['connection'] ?? 'redis://127.0.0.1:6379',
            $config['options'] ?? []
        );

        // The braces are a Redis Cluster hash tag: they keep every key on one slot.
        $this->prefix = (string) ($config['prefix'] ?? '{doppar_queue}');
    }

    /**
     * @inheritDoc
     */
    public function push(Envelope $envelope): bool
    {
        return $this->script(
            'push',
            [
                $this->job($envelope->id),
                $this->key('q', $envelope->queue, 'ready'),
                $this->key('q', $envelope->queue, 'delayed'),
                $this->key('queues'),
                $this->key('seq'),
                $this->key('unique', $envelope->uniqueKey ?? '-'),
            ],
            [
                $envelope->id,
                $envelope->queue,
                $envelope->payload,
                $envelope->priority,
                $envelope->availableAt,
                $envelope->createdAt ?: $this->now(),
                $envelope->uniqueKey ?? '',
                $this->now(),
            ]
        ) === 1;
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
            $claimed = $this->script(
                'pop',
                [
                    $this->key('q', $queue, 'ready'),
                    $this->key('q', $queue, 'delayed'),
                    $this->key('q', $queue, 'reserved'),
                ],
                [$now, $lease, $this->key('job') . ':', self::PROMOTE_LIMIT, self::SCORE_BAND]
            );

            if (is_array($claimed)) {
                return new ReservedJob(
                    (string) $claimed[0],
                    (string) $claimed[1],
                    (string) $claimed[2],
                    (int) $claimed[3],
                    $now,
                    $now + $lease
                );
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function delete(ReservedJob $job): bool
    {
        return $this->script(
            'delete',
            [$this->job((string) $job->id), $this->key('q', $job->queue, 'reserved'), $this->key('q', $job->queue, 'ready'), $this->key('q', $job->queue, 'delayed')],
            [(string) $job->id, $job->attempts, $this->key('unique') . ':']
        ) === 1;
    }

    /**
     * @inheritDoc
     */
    public function release(ReservedJob $job, int $delay = 0): bool
    {
        return $this->script(
            'release',
            [$this->job((string) $job->id), $this->key('q', $job->queue, 'reserved'), $this->key('q', $job->queue, 'ready'), $this->key('q', $job->queue, 'delayed')],
            [(string) $job->id, $job->attempts, max(0, $delay), $this->now(), self::SCORE_BAND]
        ) === 1;
    }

    /**
     * @inheritDoc
     */
    public function extend(ReservedJob $job, int $seconds): bool
    {
        return $this->script(
            'extend',
            [$this->job((string) $job->id), $this->key('q', $job->queue, 'reserved')],
            [(string) $job->id, $job->attempts, $this->now() + $seconds]
        ) === 1;
    }

    /**
     * @inheritDoc
     */
    public function fail(ReservedJob $job, string $exception): bool
    {
        return $this->script(
            'fail',
            [
                $this->job((string) $job->id),
                $this->key('q', $job->queue, 'reserved'),
                $this->key('failed'),
                $this->key('failed', 'seq'),
            ],
            [
                (string) $job->id,
                $job->attempts,
                $this->key('unique') . ':',
                $exception,
                $this->now(),
                $this->key('failed') . ':',
                (string) ($this->config['name'] ?? 'redis'),
            ]
        ) > 0;
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
        $counts = $this->script(
            'stats',
            [
                $this->key('q', $queue, 'ready'),
                $this->key('q', $queue, 'delayed'),
                $this->key('q', $queue, 'reserved'),
            ],
            [$this->now()]
        );

        return [
            'ready' => (int) ($counts[0] ?? 0),
            'delayed' => (int) ($counts[1] ?? 0),
            'reserved' => (int) ($counts[2] ?? 0),
        ];
    }

    /**
     * @inheritDoc
     */
    public function queues(): array
    {
        $names = [];

        foreach ($this->client->smembers($this->key('queues')) as $queue) {
            $stats = $this->stats((string) $queue);

            if ($stats['ready'] + $stats['delayed'] + $stats['reserved'] > 0) {
                $names[] = (string) $queue;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * @inheritDoc
     */
    public function clear(string $queue): int
    {
        $cleared = 0;

        do {
            $batch = (int) $this->script(
                'clear',
                [
                    $this->key('q', $queue, 'ready'),
                    $this->key('q', $queue, 'delayed'),
                    $this->key('q', $queue, 'reserved'),
                ],
                [$this->key('job') . ':', $this->key('unique') . ':', self::CLEAR_BATCH]
            );

            $cleared += $batch;
        } while ($batch >= self::CLEAR_BATCH);

        return $cleared;
    }

    /**
     * @inheritDoc
     */
    public function failedJobs(): array
    {
        $rows = $this->script('failed_list', [$this->key('failed')], [$this->key('failed') . ':']);

        $records = [];

        foreach (is_array($rows) ? $rows : [] as [$id, $flat]) {
            $fields = [];

            for ($i = 0, $count = count($flat); $i < $count; $i += 2) {
                $fields[$flat[$i]] = $flat[$i + 1];
            }

            $records[] = $this->failedRecord((int) $id, $fields);
        }

        return $records;
    }

    /**
     * @inheritDoc
     */
    public function findFailed(string|int $id): ?FailedJobRecord
    {
        $row = $this->client->hgetall($this->key('failed', (string) $id));

        return empty($row) ? null : $this->failedRecord((int) $id, $row);
    }

    /**
     * @inheritDoc
     */
    public function forgetFailed(string|int $id): bool
    {
        $removed = (int) $this->client->zrem($this->key('failed'), (string) $id);
        $this->client->del([$this->key('failed', (string) $id)]);

        return $removed === 1;
    }

    /**
     * @inheritDoc
     */
    public function flushFailed(): int
    {
        $ids = $this->client->zrange($this->key('failed'), 0, -1);

        foreach ($ids as $id) {
            $this->forgetFailed($id);
        }

        return count($ids);
    }

    /**
     * @inheritDoc
     */
    public function countFailed(): int
    {
        return (int) $this->client->zcard($this->key('failed'));
    }

    /**
     * @param int $id
     * @param array<string, string> $row
     * @return FailedJobRecord
     */
    private function failedRecord(int $id, array $row): FailedJobRecord
    {
        return new FailedJobRecord(
            $id,
            $row['connection'] ?? '',
            $row['queue'] ?? '',
            $row['payload'] ?? '',
            $row['exception'] ?? '',
            (int) ($row['failed_at'] ?? 0)
        );
    }

    /**
     * Build a key under the driver prefix
     *
     * @param string ...$parts
     * @return string
     */
    private function key(string ...$parts): string
    {
        return $this->prefix . ':' . implode(':', $parts);
    }

    private function job(string $id): string
    {
        return $this->key('job', $id);
    }

    /**
     * Run a named Lua script, by SHA with a fallback to sending the source
     * when the server has not cached it yet
     *
     * @param string $name
     * @param array<int, string> $keys
     * @param array<int, mixed> $args
     * @return mixed
     */
    private function script(string $name, array $keys, array $args): mixed
    {
        $source = self::SCRIPTS[$name];
        $sha = $this->shas[$name] ??= sha1($source);
        $arguments = array_merge($keys, $args);

        try {
            return $this->client->evalsha($sha, count($keys), ...$arguments);
        } catch (ServerException $e) {
            if (!str_contains($e->getMessage(), 'NOSCRIPT')) {
                throw $e;
            }

            return $this->client->eval($source, count($keys), ...$arguments);
        }
    }

    /**
     * Lua sources. Shared fragments are written into each script so that every
     * script stays a single self-contained unit.
     */
    private const SCRIPTS = [
        // KEYS: job, ready, delayed, queues, seq, unique
        // ARGV: id, queue, payload, priority, availableAt, createdAt, uniqueKey, now
        'push' => <<<'LUA'
if ARGV[7] ~= '' then
    if not redis.call('SET', KEYS[6], ARGV[1], 'NX') then
        return 0
    end
end
local seq = redis.call('INCR', KEYS[5])
redis.call('HSET', KEYS[1], 'queue', ARGV[2], 'payload', ARGV[3], 'attempts', 0,
    'priority', ARGV[4], 'seq', seq, 'created_at', ARGV[6], 'unique', ARGV[7])
redis.call('SADD', KEYS[4], ARGV[2])
if tonumber(ARGV[5]) <= tonumber(ARGV[8]) then
    redis.call('ZADD', KEYS[2], string.format('%.0f', (100 - tonumber(ARGV[4])) * 1000000000000 + seq), ARGV[1])
else
    redis.call('ZADD', KEYS[3], ARGV[5], ARGV[1])
end
return 1
LUA,

        // KEYS: ready, delayed, reserved
        // ARGV: now, lease, jobPrefix, promoteLimit, scoreBand
        'pop' => <<<'LUA'
local now = tonumber(ARGV[1])
local lease = tonumber(ARGV[2])
local prefix = ARGV[3]
local band = tonumber(ARGV[5])

local function score(id)
    local h = redis.call('HMGET', prefix .. id, 'priority', 'seq')
    if not h[1] or not h[2] then return nil end
    return string.format('%.0f', (100 - tonumber(h[1])) * band + tonumber(h[2]))
end

local function promote(from)
    local ids = redis.call('ZRANGEBYSCORE', from, '-inf', now, 'LIMIT', 0, tonumber(ARGV[4]))
    for _, id in ipairs(ids) do
        redis.call('ZREM', from, id)
        local s = score(id)
        if s then redis.call('ZADD', KEYS[1], s, id) end
    end
end

promote(KEYS[2])
promote(KEYS[3])

while true do
    local top = redis.call('ZRANGE', KEYS[1], 0, 0)
    if #top == 0 then return false end
    local id = top[1]
    redis.call('ZREM', KEYS[1], id)
    local h = redis.call('HGETALL', prefix .. id)
    if #h > 0 then
        local attempts = redis.call('HINCRBY', prefix .. id, 'attempts', 1)
        redis.call('HSET', prefix .. id, 'reserved_at', now)
        redis.call('ZADD', KEYS[3], now + lease, id)
        local payload, queue = '', ''
        for i = 1, #h, 2 do
            if h[i] == 'payload' then payload = h[i + 1] elseif h[i] == 'queue' then queue = h[i + 1] end
        end
        return {id, queue, payload, attempts}
    end
end
LUA,

        // KEYS: job, reserved, ready, delayed
        // ARGV: id, attempts, uniquePrefix
        'delete' => <<<'LUA'
local attempts = redis.call('HGET', KEYS[1], 'attempts')
if not attempts or attempts ~= ARGV[2] then return 0 end
if not redis.call('ZSCORE', KEYS[2], ARGV[1]) then return 0 end
local u = redis.call('HGET', KEYS[1], 'unique')
if u and u ~= '' and redis.call('GET', ARGV[3] .. u) == ARGV[1] then
    redis.call('DEL', ARGV[3] .. u)
end
redis.call('ZREM', KEYS[2], ARGV[1])
redis.call('ZREM', KEYS[3], ARGV[1])
redis.call('ZREM', KEYS[4], ARGV[1])
redis.call('DEL', KEYS[1])
return 1
LUA,

        // KEYS: job, reserved, ready, delayed
        // ARGV: id, attempts, delay, now, scoreBand
        'release' => <<<'LUA'
local attempts = redis.call('HGET', KEYS[1], 'attempts')
if not attempts or attempts ~= ARGV[2] then return 0 end
if not redis.call('ZSCORE', KEYS[2], ARGV[1]) then return 0 end
redis.call('ZREM', KEYS[2], ARGV[1])
redis.call('HDEL', KEYS[1], 'reserved_at')
local delay = tonumber(ARGV[3])
if delay > 0 then
    redis.call('ZADD', KEYS[4], tonumber(ARGV[4]) + delay, ARGV[1])
else
    local h = redis.call('HMGET', KEYS[1], 'priority', 'seq')
    redis.call('ZADD', KEYS[3], string.format('%.0f', (100 - tonumber(h[1])) * tonumber(ARGV[5]) + tonumber(h[2])), ARGV[1])
end
return 1
LUA,

        // KEYS: job, reserved
        // ARGV: id, attempts, leaseUntil
        'extend' => <<<'LUA'
local attempts = redis.call('HGET', KEYS[1], 'attempts')
if not attempts or attempts ~= ARGV[2] then return 0 end
if not redis.call('ZSCORE', KEYS[2], ARGV[1]) then return 0 end
redis.call('ZADD', KEYS[2], ARGV[3], ARGV[1])
return 1
LUA,

        // KEYS: job, reserved, failedIndex, failedSeq
        // ARGV: id, attempts, uniquePrefix, exception, now, failedPrefix, connection
        'fail' => <<<'LUA'
local attempts = redis.call('HGET', KEYS[1], 'attempts')
if not attempts or attempts ~= ARGV[2] then return 0 end
if not redis.call('ZSCORE', KEYS[2], ARGV[1]) then return 0 end
local h = redis.call('HMGET', KEYS[1], 'queue', 'payload', 'unique')
local fid = redis.call('INCR', KEYS[4])
redis.call('HSET', ARGV[6] .. fid, 'connection', ARGV[7], 'queue', h[1], 'payload', h[2],
    'exception', ARGV[4], 'failed_at', ARGV[5])
redis.call('ZADD', KEYS[3], ARGV[5], fid)
if h[3] and h[3] ~= '' and redis.call('GET', ARGV[3] .. h[3]) == ARGV[1] then
    redis.call('DEL', ARGV[3] .. h[3])
end
redis.call('ZREM', KEYS[2], ARGV[1])
redis.call('DEL', KEYS[1])
return fid
LUA,

        // KEYS: ready, delayed, reserved
        // ARGV: now
        'stats' => <<<'LUA'
local now = ARGV[1]
return {
    redis.call('ZCARD', KEYS[1]) + redis.call('ZCOUNT', KEYS[2], '-inf', now) + redis.call('ZCOUNT', KEYS[3], '-inf', now),
    redis.call('ZCOUNT', KEYS[2], '(' .. now, '+inf'),
    redis.call('ZCOUNT', KEYS[3], '(' .. now, '+inf')
}
LUA,

        // KEYS: failedIndex
        // ARGV: failedPrefix
        'failed_list' => <<<'LUA'
local out = {}
local ids = redis.call('ZREVRANGE', KEYS[1], 0, -1)
for _, id in ipairs(ids) do
    local h = redis.call('HGETALL', ARGV[1] .. id)
    if #h > 0 then out[#out + 1] = {id, h} end
end
return out
LUA,

        // KEYS: ready, delayed, reserved
        // ARGV: jobPrefix, uniquePrefix, batch
        'clear' => <<<'LUA'
local removed = 0
local batch = tonumber(ARGV[3])
for k = 1, 3 do
    if removed >= batch then break end
    local ids = redis.call('ZRANGE', KEYS[k], 0, batch - removed - 1)
    for _, id in ipairs(ids) do
        local u = redis.call('HGET', ARGV[1] .. id, 'unique')
        if u and u ~= '' and redis.call('GET', ARGV[2] .. u) == id then
            redis.call('DEL', ARGV[2] .. u)
        end
        redis.call('DEL', ARGV[1] .. id)
        redis.call('ZREM', KEYS[k], id)
        removed = removed + 1
    end
end
return removed
LUA,
    ];
}
