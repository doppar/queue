<?php

namespace Doppar\Queue\Tests\Unit;

use Phaseolies\Support\UrlGenerator;
use Phaseolies\Http\Request;
use Phaseolies\Database\Database;
use Phaseolies\DI\Container;
use PHPUnit\Metadata\Test;
use PHPUnit\Framework\TestCase;
use PDO;
use Doppar\Queue\Tests\Mock\TestQueueManager;
use Doppar\Queue\Tests\Mock\Models\MockQueueJob;
use Doppar\Queue\Tests\Mock\MockContainer;
use Doppar\Queue\Tests\Mock\Jobs\TestEmailJob;
use Doppar\Queue\QueueWorker;
use Doppar\Queue\QueueManager;
use Doppar\Queue\Facades\Queue;

class QueueSystemTest extends TestCase
{
    private $pdo;
    private $manager;
    private $worker;

    protected function setUp(): void
    {
        Container::setInstance(new MockContainer());
        $container = new Container();
        $container->bind('request', fn() => new Request());
        $container->bind('url', fn() => UrlGenerator::class);
        $container->bind('db', fn() => new Database('default'));
        $container->singleton('queue.worker', TestQueueManager::class);

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->createQueueTables();
        $this->setupDatabaseConnections();

        $this->manager = new QueueManager();
        $this->worker = new QueueWorker($this->manager);
    }

    protected function tearDown(): void
    {
        $this->pdo = null;
        $this->manager = null;
        $this->worker = null;
        $this->tearDownDatabaseConnections();
    }

    private function createQueueTables(): void
    {
        // Create queue_jobs table
        $this->pdo->exec("
            CREATE TABLE queue_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                queue TEXT NOT NULL,
                payload TEXT NOT NULL,
                attempts INTEGER DEFAULT 0,
                reserved_at INTEGER,
                available_at INTEGER NOT NULL,
                created_at INTEGER NOT NULL
            )
        ");

        $this->pdo->exec("
            CREATE INDEX idx_queue_reserved ON queue_jobs(queue, reserved_at)
        ");

        // Create failed_jobs table
        $this->pdo->exec("
            CREATE TABLE failed_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                connection TEXT NOT NULL,
                queue TEXT NOT NULL,
                payload TEXT NOT NULL,
                exception TEXT NOT NULL,
                failed_at INTEGER NOT NULL
            )
        ");

        $this->pdo->exec("
            CREATE INDEX idx_failed_at ON failed_jobs(failed_at)
        ");
    }

    private function setupDatabaseConnections(): void
    {
        $this->setStaticProperty(Database::class, 'connections', []);
        $this->setStaticProperty(Database::class, 'transactions', []);

        $this->setStaticProperty(Database::class, 'connections', [
            'default' => $this->pdo,
            'sqlite' => $this->pdo
        ]);
    }

    private function tearDownDatabaseConnections(): void
    {
        $this->setStaticProperty(Database::class, 'connections', []);
        $this->setStaticProperty(Database::class, 'transactions', []);
    }

    private function setStaticProperty(string $className, string $propertyName, $value): void
    {
        try {
            $reflection = new \ReflectionClass($className);
            $property = $reflection->getProperty($propertyName);
            $property->setAccessible(true);
            $property->setValue(null, $value);
            $property->setAccessible(false);
        } catch (\ReflectionException $e) {
            $this->fail("Failed to set static property {$propertyName}: " . $e->getMessage());
        }
    }

    // =====================================================
    // TEST JOB CREATION AND DISPATCHING
    // =====================================================

    public function testPushJobToQueue(): void
    {
        $job = new TestEmailJob('test@example.com', 'Test Subject');
        $jobId = Queue::push($job);

        $this->assertNotEmpty($jobId);
        $this->assertStringStartsWith('job_', $jobId);

        // Verify job is in database
        $queueJob = MockQueueJob::where('queue', 'default')->first();
        $this->assertNotNull($queueJob);
        $this->assertEquals('default', $queueJob->queue);
        $this->assertEquals(0, $queueJob->attempts);
    }

    public function testPushJobWithCustomQueue(): void
    {
        $job = new TestEmailJob('test@example.com', 'Test Subject');
        $job->onQueue('emails');
        $jobId = Queue::push($job);

        $this->assertNotEmpty($jobId);

        // Verify job is in correct queue
        $queueJob = MockQueueJob::where('queue', 'emails')->first();
        $this->assertNotNull($queueJob);
        $this->assertEquals('emails', $queueJob->queue);
    }

    public function testPushJobWithDelay(): void
    {
        $job = new TestEmailJob('test@example.com', 'Test Subject');
        $job->delayFor(300); // 5 minutes

        $beforeTime = time();
        $jobId = Queue::push($job);
        $afterTime = time();

        $queueJob = MockQueueJob::where('queue', 'default')->first();
        $this->assertNotNull($queueJob);

        // available_at should be current time + 300 seconds
        $this->assertGreaterThanOrEqual($beforeTime + 300, $queueJob->available_at);
        $this->assertLessThanOrEqual($afterTime + 300, $queueJob->available_at);
    }

    // =====================================================
    // TEST JOB RETRIEVAL
    // =====================================================

    public function testPopJobFromQueue(): void
    {
        $job = new TestEmailJob('test@example.com', 'Test Subject');
        Queue::push($job);

        $queueJob = Queue::pop('default');

        $this->assertNotNull($queueJob);
        $this->assertInstanceOf(MockQueueJob::class, $queueJob);
        $this->assertEquals(1, $queueJob->attempts);
        $this->assertNotNull($queueJob->reserved_at);
    }

    public function testPopJobRespectsAvailableAt(): void
    {
        $job = new TestEmailJob('test@example.com', 'Test Subject');
        $job->delayFor(3600); // 1 hour delay
        Queue::push($job);

        // Should not pop job that's not available yet
        $queueJob = Queue::pop('default');
        $this->assertNull($queueJob);

        // Manually update available_at to make it available
        MockQueueJob::where('queue', 'default')->update(['available_at' => time() - 1]);

        // Now it should pop
        $queueJob = Queue::pop('default');
        $this->assertNotNull($queueJob);
    }
}
