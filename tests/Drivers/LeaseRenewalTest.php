<?php

namespace Doppar\Queue\Tests\Drivers;

use Doppar\Queue\Drivers\RedisDriver;
use Doppar\Queue\QueueManager;
use Doppar\Queue\Tests\Mock\Jobs\SleepJob;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Doppar\Queue\Tests\Support\NeedsBackend;
use Predis\Client;

/**
 * A job that outlives the queue lease must not be handed to a second worker
 * while the first is still running it. Uses real processes, a real fork for
 * the job timeout, and real time.
 */
#[Group('concurrency')]
#[Group('slow')]
class LeaseRenewalTest extends TestCase
{
    use NeedsBackend;

    private const LEASE = 3;

    private const JOB_SECONDS = 6;

    private string $prefix;

    private Client $client;

    protected function setUp(): void
    {
        if (!extension_loaded('pcntl') || !function_exists('proc_open')) {
            $this->backendUnavailable('pcntl and proc_open are required.');
        }

        $this->client = RedisDriverTest::redisClient();

        try {
            $this->client->ping();
        } catch (\Throwable $e) {
            $this->backendUnavailable('Redis is not reachable: ' . $e->getMessage());
        }

        $this->prefix = '{dqlease_' . bin2hex(random_bytes(6)) . '}';
    }

    protected function tearDown(): void
    {
        if (!isset($this->client)) {
            return;
        }

        $cursor = '0';

        do {
            [$cursor, $keys] = $this->client->scan($cursor, ['MATCH' => $this->prefix . ':*', 'COUNT' => 500]);

            foreach (array_chunk($keys, 200) as $chunk) {
                $this->client->del($chunk);
            }
        } while ($cursor !== '0');
    }

    private function driver(): RedisDriver
    {
        return new RedisDriver(['prefix' => $this->prefix, 'lease' => self::LEASE], $this->client);
    }

    /**
     * Run the slow job in a worker process while this process tries to claim it.
     *
     * @return array{claimedByRival: bool, workerOutput: string, exit: int}
     */
    private function raceAgainstSlowJob(int $renewEvery): array
    {
        $manager = new QueueManager(['default' => 'redis', 'connections' => ['redis' => [
            'driver' => 'redis',
            'prefix' => $this->prefix,
            'lease' => self::LEASE,
            'options' => ['parameters' => ['database' => (int) (getenv('QUEUE_TEST_REDIS_DB') ?: 15)]],
        ]]]);
        $manager->extend('redis', fn() => $this->driver());
        $manager->push(new SleepJob(self::JOB_SECONDS));

        $spec = base64_encode(json_encode([
            'url' => getenv('QUEUE_TEST_REDIS_URL') ?: 'redis://127.0.0.1:6379',
            'db' => (int) (getenv('QUEUE_TEST_REDIS_DB') ?: 15),
            'prefix' => $this->prefix,
            'lease' => self::LEASE,
            'renew_every' => $renewEvery,
        ]));

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../Support/slow_job_worker.php', $spec],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        stream_set_blocking($pipes[1], false);

        $claimedByRival = false;
        $deadline = microtime(true) + self::JOB_SECONDS + 3;

        // Give the worker time to claim the job, then keep trying to take it.
        usleep(800_000);

        while (microtime(true) < $deadline) {
            $status = proc_get_status($process);

            if (!$status['running']) {
                break;
            }

            if ($this->driver()->pop('default') !== null) {
                $claimedByRival = true;
                break;
            }

            usleep(250_000);
        }

        stream_set_blocking($pipes[1], true);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return ['claimedByRival' => $claimedByRival, 'workerOutput' => $output, 'exit' => $exit];
    }

    public function testALongRunningJobKeepsItsLeaseAndIsNotStolen(): void
    {
        $result = $this->raceAgainstSlowJob(renewEvery: 1);

        $this->assertFalse($result['claimedByRival'], 'a second worker took a job that is still running');
        $this->assertStringContainsString('processed', $result['workerOutput']);
        $this->assertStringNotContainsString('lease was lost', $result['workerOutput']);
        $this->assertSame(['ready' => 0, 'delayed' => 0, 'reserved' => 0], $this->driver()->stats('default'));
        $this->assertSame(0, $this->driver()->countFailed());
    }

    public function testWithoutRenewalTheSameJobIsStolenWhichProvesTheTestCanFail(): void
    {
        $result = $this->raceAgainstSlowJob(renewEvery: 3600);

        $this->assertTrue($result['claimedByRival'], 'without renewal the lease must run out mid-job');
        $this->assertStringContainsString('lease was lost', $result['workerOutput']);
    }
}
