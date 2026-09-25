<?php

namespace Doppar\Queue\Tests\Drivers;

use PDO;
use Doppar\Queue\Contracts\QueueDriver;
use Doppar\Queue\Drivers\DatabaseDriver;
use Doppar\Queue\Drivers\RedisDriver;
use Doppar\Queue\Support\Envelope;
use Doppar\Queue\Tests\Support\QueueSchema;
use Phaseolies\Database\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Doppar\Queue\Tests\Support\NeedsBackend;

/**
 * Races real operating-system processes against one shared backend. In-process
 * tests cannot prove atomic claiming; this can.
 */
#[Group('concurrency')]
class ConcurrentClaimTest extends TestCase
{
    use NeedsBackend;

    private const WORKERS = 6;

    private const JOBS = 600;

    private string $sqliteFile;

    private string $redisPrefix;

    private ?PDO $mysqlPdo = null;

    private ?PDO $pgsqlPdo = null;

    /**
     * @return array<string, array{string}>
     */
    public static function backends(): array
    {
        return ['sqlite file' => ['sqlite'], 'redis' => ['redis'], 'mysql' => ['mysql'], 'pgsql' => ['pgsql']];
    }

    protected function setUp(): void
    {
        if (!function_exists('proc_open') || !function_exists('posix_kill')) {
            $this->backendUnavailable('proc_open and posix are required.');
        }

        $this->sqliteFile = sys_get_temp_dir() . '/dq_concurrency_' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->redisPrefix = '{dqconc_' . bin2hex(random_bytes(6)) . '}';
    }

    protected function tearDown(): void
    {
        foreach ([$this->sqliteFile, $this->sqliteFile . '-wal', $this->sqliteFile . '-shm'] as $file) {
            @unlink($file);
        }

        (new \ReflectionProperty(Database::class, 'connections'))->setValue(null, []);

        $this->mysqlPdo?->exec('DROP TABLE IF EXISTS queue_jobs, failed_jobs');
        $this->pgsqlPdo?->exec('DROP TABLE IF EXISTS queue_jobs, failed_jobs');

        if (isset($this->redisPrefix)) {
            try {
                $client = RedisDriverTest::redisClient();
                $cursor = '0';

                do {
                    [$cursor, $keys] = $client->scan($cursor, ['MATCH' => $this->redisPrefix . ':*', 'COUNT' => 500]);

                    foreach (array_chunk($keys, 200) as $chunk) {
                        $client->del($chunk);
                    }
                } while ($cursor !== '0');
            } catch (\Throwable) {
                // Redis was not in use.
            }
        }
    }

    private function driver(string $backend, int $lease): QueueDriver
    {
        if ($backend === 'pgsql') {
            $dsn = getenv('QUEUE_TEST_PGSQL_DSN');
            $schema = (string) getenv('QUEUE_TEST_PGSQL_SCHEMA');

            if (!$dsn || !preg_match('/^[a-z0-9_]*(test|scratch)[a-z0-9_]*$/i', $schema)) {
                $this->backendUnavailable('Set QUEUE_TEST_PGSQL_DSN and a QUEUE_TEST_PGSQL_SCHEMA containing "test" or "scratch".');
            }

            $pdo = new PDO($dsn, getenv('QUEUE_TEST_PGSQL_USER') ?: null, getenv('QUEUE_TEST_PGSQL_PASS') ?: null);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // The name was checked above, so it is safe to create it when missing.
            $pdo->exec("CREATE SCHEMA IF NOT EXISTS {$schema}");
            $pdo->exec("SET search_path TO {$schema}");

            if (trim((string) $pdo->query('SHOW search_path')->fetchColumn(), '" ') !== $schema) {
                $this->backendUnavailable('Could not pin search_path to the scratch schema.');
            }

            $pdo->exec('DROP TABLE IF EXISTS queue_jobs, failed_jobs');
            QueueSchema::createPgsql($pdo);
            $this->pgsqlPdo = $pdo;
            (new \ReflectionProperty(Database::class, 'connections'))->setValue(null, ['contract' => $pdo]);

            return new DatabaseDriver(['connection' => 'contract', 'lease' => $lease]);
        }

        if ($backend === 'mysql') {
            $dsn = getenv('QUEUE_TEST_MYSQL_DSN');

            if (!$dsn) {
                $this->backendUnavailable('Set QUEUE_TEST_MYSQL_DSN to run the MySQL concurrency tests.');
            }

            $pdo = new PDO($dsn, getenv('QUEUE_TEST_MYSQL_USER') ?: null, getenv('QUEUE_TEST_MYSQL_PASS') ?: null);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            if (!preg_match('/test|scratch/i', (string) $pdo->query('SELECT DATABASE()')->fetchColumn())) {
                $this->backendUnavailable('Refusing to drop tables: the database name must contain "test" or "scratch".');
            }

            $pdo->exec('DROP TABLE IF EXISTS queue_jobs, failed_jobs');
            QueueSchema::createMysql($pdo);
            $this->mysqlPdo = $pdo;
            (new \ReflectionProperty(Database::class, 'connections'))->setValue(null, ['contract' => $pdo]);

            return new DatabaseDriver(['connection' => 'contract', 'lease' => $lease]);
        }

        if ($backend === 'sqlite') {
            $pdo = new PDO('sqlite:' . $this->sqliteFile);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 30000');
            QueueSchema::create($pdo);
            (new \ReflectionProperty(Database::class, 'connections'))->setValue(null, ['contract' => $pdo]);

            return new DatabaseDriver(['connection' => 'contract', 'lease' => $lease]);
        }

        $client = RedisDriverTest::redisClient();

        try {
            $client->ping();
        } catch (\Throwable $e) {
            $this->backendUnavailable('Redis is not reachable: ' . $e->getMessage());
        }

        return new RedisDriver(['prefix' => $this->redisPrefix, 'lease' => $lease], $client);
    }

    /**
     * @return array<string, mixed>
     */
    private function spec(string $backend, int $lease, string $mode, float $startAt): array
    {
        return [
            'backend' => $backend,
            'file' => $this->sqliteFile,
            'prefix' => $this->redisPrefix,
            'url' => getenv('QUEUE_TEST_REDIS_URL') ?: 'redis://127.0.0.1:6379',
            'db' => (int) (getenv('QUEUE_TEST_REDIS_DB') ?: 15),
            'pg_dsn' => getenv('QUEUE_TEST_PGSQL_DSN') ?: '',
            'pg_user' => getenv('QUEUE_TEST_PGSQL_USER') ?: null,
            'pg_pass' => getenv('QUEUE_TEST_PGSQL_PASS') ?: null,
            'pg_schema' => (string) getenv('QUEUE_TEST_PGSQL_SCHEMA'),
            'dsn' => getenv('QUEUE_TEST_MYSQL_DSN') ?: '',
            'user' => getenv('QUEUE_TEST_MYSQL_USER') ?: null,
            'pass' => getenv('QUEUE_TEST_MYSQL_PASS') ?: null,
            'lease' => $lease,
            'mode' => $mode,
            'start_at' => $startAt,
        ];
    }

    /**
     * Start a claiming worker process.
     *
     * @return array{resource, array<int, resource>}
     */
    private function spawn(array $spec): array
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../Support/claim_worker.php', base64_encode(json_encode($spec))],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        $this->assertIsResource($process, 'could not start a worker process');

        return [$process, $pipes];
    }

    /**
     * @return array{array<int, string>, string, int} claimed payloads, stderr, exit code
     */
    private function collect(array $worker): array
    {
        [$process, $pipes] = $worker;

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        $lines = array_values(array_filter(explode("\n", $stdout), fn($line) => $line !== ''));

        return [$lines, $stderr, $exit];
    }

    #[DataProvider('backends')]
    public function testConcurrentWorkersClaimEveryJobExactlyOnce(string $backend): void
    {
        $driver = $this->driver($backend, 300);
        $now = time();

        $envelopes = [];

        for ($i = 0; $i < self::JOBS; $i++) {
            $envelopes[] = new Envelope("job-{$i}", 'default', "job-{$i}", $now, 0, null, $now);
        }

        $driver->pushMany($envelopes);

        $startAt = microtime(true) + 1.0;
        $workers = [];

        for ($w = 0; $w < self::WORKERS; $w++) {
            $workers[] = $this->spawn($this->spec($backend, 300, 'drain', $startAt));
        }

        $claimedBy = [];
        $all = [];

        foreach ($workers as $index => $worker) {
            [$claimed, $stderr, $exit] = $this->collect($worker);

            $this->assertSame('', $stderr, "worker {$index} wrote to stderr");
            $this->assertSame(0, $exit, "worker {$index} exited abnormally");

            $claimedBy[$index] = count($claimed);
            $all = array_merge($all, $claimed);
        }

        $this->assertCount(self::JOBS, $all, 'every job must be claimed');
        $this->assertSame([], array_diff_key($all, array_unique($all)), 'no job may be claimed by two workers');
        $this->assertCount(self::JOBS, array_unique($all));

        $this->assertGreaterThan(
            1,
            count(array_filter($claimedBy)),
            'the test is only meaningful if several workers took part: ' . json_encode($claimedBy)
        );

        $this->assertSame(['ready' => 0, 'delayed' => 0, 'reserved' => 0], $driver->stats('default'));
    }

    #[DataProvider('backends')]
    public function testJobOfAKilledWorkerIsRecoveredAfterItsLeaseExpires(string $backend): void
    {
        $driver = $this->driver($backend, 2);
        $now = time();

        $driver->push(new Envelope('only', 'default', 'only-job', $now, 0, null, $now));

        $worker = $this->spawn($this->spec($backend, 2, 'crash', microtime(true)));
        [$claimed] = $this->collect($worker);

        $this->assertSame(['only-job'], $claimed, 'the doomed worker claimed the job');
        $this->assertNull($driver->pop('default'), 'the dead worker still holds the lease');

        sleep(3);

        $recovered = $driver->pop('default');

        $this->assertNotNull($recovered, 'the job must come back once the lease has expired');
        $this->assertSame('only-job', $recovered->payload);
        $this->assertSame(2, $recovered->attempts);
    }
}
