<?php

namespace Doppar\Queue\Tests\Drivers;

use Doppar\Queue\Contracts\QueueDriver;
use Doppar\Queue\Drivers\RedisDriver;
use Doppar\Queue\Support\Envelope;
use Doppar\Queue\Tests\Contract\QueueDriverContract;
use Predis\Client;

/**
 * Runs against a real Redis server, on its own database index and under a
 * unique key prefix that is removed afterwards, so it never touches other data.
 * Skipped when no server is reachable.
 *
 * QUEUE_TEST_REDIS_URL (default redis://127.0.0.1:6379) and
 * QUEUE_TEST_REDIS_DB (default 15) point it elsewhere.
 */
class RedisDriverTest extends QueueDriverContract
{
    private Client $client;

    private string $prefix;

    protected function makeDriver(\Closure $clock): QueueDriver
    {
        $this->client = self::redisClient();

        try {
            $this->client->ping();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis is not reachable: ' . $e->getMessage());
        }

        $this->prefix = '{dqtest_' . bin2hex(random_bytes(6)) . '}';

        return new RedisDriver(['prefix' => $this->prefix, 'lease' => self::LEASE], $this->client, $clock);
    }

    protected function tearDown(): void
    {
        if (isset($this->client, $this->prefix)) {
            $this->deleteKeys($this->allKeys());
        }
    }

    public static function redisClient(): Client
    {
        return new Client(
            getenv('QUEUE_TEST_REDIS_URL') ?: 'redis://127.0.0.1:6379',
            ['parameters' => ['database' => (int) (getenv('QUEUE_TEST_REDIS_DB') ?: 15)]]
        );
    }

    /**
     * @return array<int, string>
     */
    private function allKeys(): array
    {
        $keys = [];
        $cursor = '0';

        do {
            [$cursor, $batch] = $this->client->scan($cursor, ['MATCH' => $this->prefix . ':*', 'COUNT' => 500]);
            $keys = array_merge($keys, $batch);
        } while ($cursor !== '0');

        return array_values(array_unique($keys));
    }

    /**
     * @param array<int, string> $keys
     */
    private function deleteKeys(array $keys): void
    {
        foreach (array_chunk($keys, 200) as $chunk) {
            $this->client->del($chunk);
        }
    }

    public function testEveryKeyLivesUnderTheConfiguredPrefixWithAHashTag(): void
    {
        $this->push('a', unique: 'k');
        $this->push('b', delay: 30);
        $this->driver->fail($this->driver->pop('default'), 'boom');

        $keys = $this->allKeys();

        $this->assertNotEmpty($keys);
        $this->assertMatchesRegularExpression('/^\{dqtest_[0-9a-f]+\}$/', $this->prefix);

        // Redis Cluster puts keys on the slot of the text inside the first braces.
        foreach ($keys as $key) {
            $this->assertStringStartsWith($this->prefix . ':', $key);
        }
    }

    public function testFinishedJobsLeaveNoKeysBehind(): void
    {
        $this->push('a', unique: 'k');
        $this->driver->delete($this->driver->pop('default'));

        $leftovers = array_filter(
            $this->allKeys(),
            fn(string $key) => !in_array(substr($key, strlen($this->prefix) + 1), ['seq', 'queues', 'q:default:ready', 'q:default:delayed', 'q:default:reserved'], true)
        );

        $this->assertSame([], array_values($leftovers), 'job hashes and unique keys must be removed');
    }

    public function testClearLeavesNoJobOrUniqueKeysBehind(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->push("j{$i}", unique: "u{$i}");
        }

        $this->driver->clear('default');

        foreach ($this->allKeys() as $key) {
            $this->assertStringNotContainsString(':job:', $key);
            $this->assertStringNotContainsString(':unique:', $key);
        }
    }

    public function testWorksAfterTheServerScriptCacheIsFlushed(): void
    {
        $this->push('a');
        $this->assertNotNull($this->driver->pop('default'));

        // Every script is now unknown to the server; the driver must fall back
        // to sending the source instead of failing with NOSCRIPT.
        $this->client->script('FLUSH');

        $this->assertTrue($this->push('b'));
        $this->assertSame('payload-b', $this->driver->pop('default')?->payload);
    }

    public function testAJobWhoseHashWasLostIsSkippedNotClaimed(): void
    {
        $this->push('lost');
        $this->push('kept');

        // Simulate an eviction or manual deletion of the job data.
        foreach ($this->allKeys() as $key) {
            if (str_contains($key, ':job:') && $this->client->hget($key, 'payload') === 'payload-lost') {
                $this->client->del([$key]);
            }
        }

        $this->assertSame(['payload-kept'], $this->drain());
    }

    public function testReadyOrderingSurvivesLargeSequenceNumbers(): void
    {
        // Score = (100 - priority) * 1e12 + sequence; jump the counter close to the
        // top of a band and check ordering between priorities still holds.
        $this->client->set($this->prefix . ':seq', 900_000_000_000);

        $this->push('low', priority: 0);
        $this->push('high', priority: 1);

        $this->assertSame(['payload-high', 'payload-low'], $this->drain());
    }

    public function testJobsFromSeparatePrefixesDoNotMix(): void
    {
        $other = new RedisDriver(
            ['prefix' => '{dqtest_other_' . bin2hex(random_bytes(4)) . '}', 'lease' => self::LEASE],
            $this->client,
            fn(): int => $this->now
        );

        $this->push('mine');

        $this->assertNull($other->pop('default'));
        $this->assertSame(['payload-mine'], $this->drain());
    }

    public function testBuildsItsOwnClientFromConnectionConfig(): void
    {
        $driver = new RedisDriver([
            'connection' => getenv('QUEUE_TEST_REDIS_URL') ?: 'redis://127.0.0.1:6379',
            'options' => ['parameters' => ['database' => (int) (getenv('QUEUE_TEST_REDIS_DB') ?: 15)]],
            'prefix' => $this->prefix,
            'lease' => self::LEASE,
        ], null, fn(): int => $this->now);

        $driver->push(new Envelope('x', 'default', 'payload-x', $this->now, 0, null, $this->now));

        $this->assertSame('payload-x', $this->driver->pop('default')?->payload);
    }
}
