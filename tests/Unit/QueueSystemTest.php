<?php

namespace Doppar\Queue\Tests\Unit;

use Phaseolies\Support\UrlGenerator;
use Phaseolies\Support\LoggerService;
use Phaseolies\Http\Request;
use Phaseolies\Database\Database;
use Phaseolies\DI\Container;
use PHPUnit\Framework\TestCase;
use PDO;
use Doppar\Queue\Tests\Mock\TestQueueManager;
use Doppar\Queue\Tests\Mock\Models\MockQueueJob;
use Doppar\Queue\Tests\Mock\Models\MockFailedJob;
use Doppar\Queue\Tests\Mock\MockContainer;
use Doppar\Queue\Tests\Mock\Jobs\TestJobWithFailedCallback;
use Doppar\Queue\Tests\Mock\Jobs\TestImageJob;
use Doppar\Queue\Tests\Mock\Jobs\TestFailingJob;
use Doppar\Queue\Tests\Mock\Jobs\TestEmailJob;
use Doppar\Queue\Tests\Mock\Jobs\TestComplexDataJob;
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
        $container->singleton('log', LoggerService::class);

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

    public function testPopJobFromEmptyQueue(): void
    {
        $queueJob = $this->manager->pop('default');
        $this->assertNull($queueJob);
    }

    public function testPopJobFromSpecificQueue(): void
    {
        $emailJob = new TestEmailJob('test@example.com', 'Subject');
        $emailJob->onQueue('emails');
        Queue::push($emailJob);

        $imageJob = new TestImageJob('/path/to/image.jpg');
        $imageJob->onQueue('images');
        Queue::push($imageJob);

        // Pop from emails queue
        $queueJob = Queue::pop('emails');
        $this->assertNotNull($queueJob);
        $this->assertEquals('emails', $queueJob->queue);

        // Pop from images queue
        $queueJob = Queue::pop('images');
        $this->assertNotNull($queueJob);
        $this->assertEquals('images', $queueJob->queue);
    }

    // =====================================================
    // TEST JOB EXECUTION
    // =====================================================

    public function testJobExecutionSuccess(): void
    {
        $job = new TestEmailJob('test@example.com', 'Test Subject');
        Queue::push($job);

        $queueJob = Queue::pop('default');
        $this->assertNotNull($queueJob);

        // Execute the job
        $unserializedJob = $this->manager->unserializeJob($queueJob->payload);
        $unserializedJob->handle();

        // Job should have been executed
        $this->assertEquals('test@example.com', $unserializedJob->to);
        $this->assertEquals('Test Subject', $unserializedJob->subject);

        // Delete job after successful execution
        $deleted = Queue::delete($queueJob);
        $this->assertTrue($deleted);

        // Verify job is removed
        $count = MockQueueJob::count();
        $this->assertEquals(0, $count);
    }

    public function testJobExecutionFailureAndRetry(): void
    {
        $job = new TestFailingJob();
        $job->tries = 3;
        $job->retryAfter = 60;
        Queue::push($job);

        // First attempt
        $queueJob = Queue::pop('default');
        $this->assertEquals(1, $queueJob->attempts);

        try {
            $unserializedJob = $this->manager->unserializeJob($queueJob->payload);
            $unserializedJob->handle();
            $this->fail('Job should have thrown an exception');
        } catch (\Exception $e) {
            // Job failed, release it back
            $released = Queue::release($queueJob, $job->retryAfter);
            $this->assertTrue($released);
        }

        // Verify job was released
        $queueJob = MockQueueJob::find($queueJob->id);
        $this->assertNull($queueJob->reserved_at);
        $this->assertEquals(1, $queueJob->attempts);
    }

    public function testJobMovedToFailedAfterMaxAttempts(): void
    {
        $job = new TestFailingJob();
        $job->tries = 2;
        Queue::push($job);

        // First attempt
        $queueJob = Queue::pop('default');
        try {
            $unserializedJob = $this->manager->unserializeJob($queueJob->payload);
            $unserializedJob->handle();
        } catch (\Exception $e) {
            Queue::release($queueJob, 0);
        }

        // Make job available immediately
        MockQueueJob::where('id', $queueJob->id)->update(['available_at' => time() - 1]);

        // Second attempt
        $queueJob = Queue::pop('default');
        $this->assertEquals(2, $queueJob->attempts);

        try {
            $unserializedJob = $this->manager->unserializeJob($queueJob->payload);
            $unserializedJob->handle();
        } catch (\Exception $e) {
            // Max attempts reached, mark as failed
            Queue::markAsFailed($queueJob, $e);
        }

        // Verify job is in failed_jobs table
        $failedJob = MockFailedJob::where('queue', 'default')->first();
        $this->assertNotNull($failedJob);
        $this->assertStringContainsString('Test failure', $failedJob->exception);

        // Verify job is removed from queue_jobs
        $queueJob = MockQueueJob::find($queueJob->id);
        $this->assertNull($queueJob);
    }

    // =====================================================
    // TEST QUEUE OPERATIONS
    // =====================================================

    public function testQueueSize(): void
    {
        $job1 = new TestEmailJob('test1@example.com', 'Subject 1');
        $job2 = new TestEmailJob('test2@example.com', 'Subject 2');
        $job3 = new TestEmailJob('test3@example.com', 'Subject 3');

        Queue::push($job1);
        Queue::push($job2);
        Queue::push($job3);

        $size = Queue::size('default');
        $this->assertEquals(3, $size);
    }

    public function testQueueClear(): void
    {
        $job1 = new TestEmailJob('test1@example.com', 'Subject 1');
        $job2 = new TestEmailJob('test2@example.com', 'Subject 2');
        $job3 = new TestEmailJob('test3@example.com', 'Subject 3');

        Queue::push($job1);
        Queue::push($job2);
        Queue::push($job3);

        $deleted = Queue::clear('default');
        $this->assertEquals(1, $deleted);

        $size = Queue::size('default');
        $this->assertEquals(0, $size);
    }

    public function testClearSpecificQueue(): void
    {
        $emailJob = new TestEmailJob('test@example.com', 'Subject');
        $emailJob->onQueue('emails');
        Queue::push($emailJob);

        $imageJob = new TestImageJob('/path/to/image.jpg');
        $imageJob->onQueue('images');
        Queue::push($imageJob);

        // Clear only emails queue
        $deleted = Queue::clear('emails');
        $this->assertEquals(1, $deleted);

        // Verify images queue is intact
        $size = Queue::size('images');
        $this->assertEquals(1, $size);
    }

    // =====================================================
    // TEST JOB SERIALIZATION
    // =====================================================

    public function testJobSerialization(): void
    {
        $job = new TestEmailJob('test@example.com', 'Test Subject');
        $job->setJobId('test_job_123');
        $jobId = Queue::push($job);

        $queueJob = MockQueueJob::where('queue', 'default')->first();
        $this->assertNotNull($queueJob);

        $unserializedJob = $this->manager->unserializeJob($queueJob->payload);

        $this->assertInstanceOf(TestEmailJob::class, $unserializedJob);
        $this->assertEquals('test@example.com', $unserializedJob->to);
        $this->assertEquals('Test Subject', $unserializedJob->subject);
    }

    public function testJobSerializationWithComplexData(): void
    {
        $job = new TestComplexDataJob([
            'user' => ['id' => 1, 'name' => 'John Doe'],
            'settings' => ['timezone' => 'UTC', 'theme' => 'dark'],
            'tags' => ['php', 'laravel', 'queue']
        ]);
        Queue::push($job);

        $queueJob = MockQueueJob::where('queue', 'default')->first();
        $unserializedJob = $this->manager->unserializeJob($queueJob->payload);

        $this->assertEquals('John Doe', $unserializedJob->data['user']['name']);
        $this->assertEquals(['php', 'laravel', 'queue'], $unserializedJob->data['tags']);
    }

    // =====================================================
    // TEST FAILED JOBS
    // =====================================================

    public function testFailedJobStorage(): void
    {
        $job = new TestFailingJob();
        Queue::push($job);

        $queueJob = Queue::pop('default');

        try {
            $unserializedJob = $this->manager->unserializeJob($queueJob->payload);
            $unserializedJob->handle();
        } catch (\Exception $e) {
            Queue::markAsFailed($queueJob, $e);
        }

        $failedJob = MockFailedJob::first();
        $this->assertNotNull($failedJob);
        $this->assertEquals('database', $failedJob->connection);
        $this->assertEquals('default', $failedJob->queue);
        $this->assertStringContainsString('RuntimeException', $failedJob->exception);
        $this->assertStringContainsString('Test failure', $failedJob->exception);
    }

    public function testFailedJobCallback(): void
    {
        $job = new TestJobWithFailedCallback();
        Queue::push($job);

        $queueJob = Queue::pop('default');

        try {
            $unserializedJob = $this->manager->unserializeJob($queueJob->payload);
            $unserializedJob->handle();
        } catch (\Exception $e) {
            Queue::markAsFailed($queueJob, $e);
            $unserializedJob->failed($e);
        }

        // The failed callback should have been called
        $this->assertTrue($unserializedJob->failedCalled);
    }

    // =====================================================
    // TEST QUEUE MODELS
    // =====================================================

    public function testQueueJobModel(): void
    {
        $job = new TestEmailJob('test@example.com', 'Subject');
        Queue::push($job);

        $queueJob = MockQueueJob::where('queue', 'default')->first();

        $this->assertInstanceOf(MockQueueJob::class, $queueJob);
        $this->assertEquals('default', $queueJob->queue);
        $this->assertEquals(0, $queueJob->attempts);
        $this->assertNull($queueJob->reserved_at);
    }

    public function testQueueJobReserve(): void
    {
        $job = new TestEmailJob('test@example.com', 'Subject');
        Queue::push($job);

        $queueJob = MockQueueJob::available('default')->first();
        $this->assertNotNull($queueJob);

        $reserved = $queueJob->reserve();
        $this->assertTrue($reserved);
        $this->assertEquals(1, $queueJob->attempts);
        $this->assertNotNull($queueJob->reserved_at);
    }

    public function testQueueJobRelease(): void
    {
        $job = new TestEmailJob('test@example.com', 'Subject');
        Queue::push($job);

        $queueJob = MockQueueJob::available('default')->first();
        $queueJob->reserve();

        $released = $queueJob->release(60);
        $this->assertTrue($released);
        $this->assertNull($queueJob->reserved_at);
        $this->assertGreaterThan(time(), $queueJob->available_at);
    }

    public function testFailedJobModel(): void
    {
        $failedJob = MockFailedJob::create([
            'connection' => 'database',
            'queue' => 'default',
            'payload' => serialize(['test' => 'data']),
            'exception' => 'Test exception',
            'failed_at' => time(),
        ]);

        $this->assertInstanceOf(MockFailedJob::class, $failedJob);
        $this->assertEquals('database', $failedJob->connection);
        $this->assertEquals('default', $failedJob->queue);
    }
}
