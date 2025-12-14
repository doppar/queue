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
use Doppar\Queue\Tests\Mock\Jobs\TestReportJob;
use Doppar\Queue\Tests\Mock\Jobs\TestJobWithFailedCallback;
use Doppar\Queue\Tests\Mock\Jobs\TestImageJob;
use Doppar\Queue\Tests\Mock\Jobs\TestFailingJob;
use Doppar\Queue\Tests\Mock\Jobs\TestEmailJob;
use Doppar\Queue\Tests\Mock\Jobs\TestCounterJob;
use Doppar\Queue\Tests\Mock\Jobs\TestComplexDataJob;
use Doppar\Queue\Tests\Mock\Jobs\TestChainJobC;
use Doppar\Queue\Tests\Mock\Jobs\TestChainJobB;
use Doppar\Queue\Tests\Mock\Jobs\TestChainJobA;
use Doppar\Queue\Tests\Mock\Jobs\TestChainFailingJob;
use Doppar\Queue\Tests\Mock\Class\ChainTestState;
use Doppar\Queue\QueueWorker;
use Doppar\Queue\QueueManager;
use Doppar\Queue\Facades\Queue;
use Doppar\Queue\Drain;

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

        TestChainJobA::reset();
        TestChainJobB::reset();
        TestChainJobC::reset();
        TestChainFailingJob::reset();
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

    // =====================================================
    // TEST WORKER BEHAVIOR
    // =====================================================

    public function testWorkerProcessSingleJob(): void
    {
        $job = new TestCounterJob();
        Queue::push($job);

        // Process one job
        $queueJob = Queue::pop('default');
        $this->assertNotNull($queueJob);

        $unserializedJob = $this->manager->unserializeJob($queueJob->payload);
        $unserializedJob->handle();

        $this->assertEquals(1, $unserializedJob->counter);

        // Delete job
        Queue::delete($queueJob);

        // Queue should be empty
        $this->assertEquals(0, Queue::size('default'));
    }

    public function testWorkerMemoryCheck(): void
    {
        $this->worker->setMaxMemory(1); // 1MB limit

        // This should return true since we're using more than 1MB
        $reflection = new \ReflectionClass($this->worker);
        $method = $reflection->getMethod('memoryExceeded');
        $method->setAccessible(true);

        $exceeded = $method->invoke($this->worker);
        $this->assertTrue($exceeded);
    }

    // =====================================================
    // TEST MULTIPLE QUEUES
    // =====================================================

    public function testMultipleQueues(): void
    {
        // Create jobs on different queues
        $emailJob = new TestEmailJob('test@example.com', 'Subject');
        $emailJob->onQueue('emails');
        Queue::push($emailJob);

        $imageJob = new TestImageJob('/path/to/image.jpg');
        $imageJob->onQueue('images');
        Queue::push($imageJob);

        $reportJob = new TestReportJob('monthly');
        $reportJob->onQueue('reports');
        Queue::push($reportJob);

        // Verify each queue has correct job
        $this->assertEquals(1, Queue::size('emails'));
        $this->assertEquals(1, Queue::size('images'));
        $this->assertEquals(1, Queue::size('reports'));
        $this->assertEquals(0, Queue::size('default'));
    }

    public function testJobWithZeroRetries(): void
    {
        $job = new TestFailingJob();
        $job->tries = 0; // No retries
        Queue::push($job);

        $queueJob = Queue::pop('default');

        try {
            $unserializedJob = $this->manager->unserializeJob($queueJob->payload);
            $unserializedJob->handle();
        } catch (\Exception $e) {
            // Should mark as failed immediately
            Queue::markAsFailed($queueJob, $e);
        }

        $failedJob = MockFailedJob::first();
        $this->assertNotNull($failedJob);
    }

    public function testJobIdGeneration(): void
    {
        $job1 = new TestEmailJob('test1@example.com', 'Subject 1');
        $job2 = new TestEmailJob('test2@example.com', 'Subject 2');

        $jobId1 = Queue::push($job1);
        $jobId2 = Queue::push($job2);

        $this->assertNotEquals($jobId1, $jobId2);
        $this->assertStringStartsWith('job_', $jobId1);
        $this->assertStringStartsWith('job_', $jobId2);
    }

    // =====================================================
    // TEST QUERY BINDING
    // =====================================================

    public function testAvailableJobsScope(): void
    {
        // Create two available jobs
        $job1 = new TestEmailJob('test1@example.com', 'Subject 1');
        Queue::push($job1);

        $job2 = new TestEmailJob('test2@example.com', 'Subject 2');
        Queue::push($job2);

        // Create delayed job (not available yet)
        $job3 = new TestEmailJob('test3@example.com', 'Subject 3');
        $job3->delayFor(3600);
        Queue::push($job3);

        // Before popping: 2 available jobs (job1 and job2), 1 delayed (job3)
        $available = MockQueueJob::available('default')->count();
        $this->assertEquals(2, $available);

        // Pop one job (makes it reserved)
        Queue::pop('default');

        // After popping: 1 available job (job2), 1 reserved (job1), 1 delayed (job3)
        $available = MockQueueJob::available('default')->count();
        $this->assertEquals(1, $available);

        // Verify total jobs in database
        $total = MockQueueJob::where('queue', 'default')->count();
        $this->assertEquals(3, $total);
    }

    // =====================================================
    // TEST INTEGRATION
    // =====================================================

    public function testEndToEndJobProcessing(): void
    {
        // Create multiple jobs
        $jobs = [
            new TestEmailJob('user1@example.com', 'Welcome'),
            new TestEmailJob('user2@example.com', 'Newsletter'),
            new TestEmailJob('user3@example.com', 'Update'),
        ];

        foreach ($jobs as $job) {
            Queue::push($job);
        }

        $this->assertEquals(3, Queue::size('default'));

        // Process all jobs
        $processed = 0;
        while ($queueJob = Queue::pop('default')) {
            $unserializedJob = $this->manager->unserializeJob($queueJob->payload);
            $unserializedJob->handle();
            Queue::delete($queueJob);
            $processed++;
        }

        $this->assertEquals(3, $processed);
        $this->assertEquals(0, Queue::size('default'));
        $this->assertEquals(0, MockFailedJob::count());
    }

    // =====================================================
    // TEST JOB CHAINING
    // =====================================================

    public function testJobChainCreation(): void
    {
        $chain = Drain::conduct([
            new TestChainJobA(),
            new TestChainJobB(),
            new TestChainJobC(),
        ]);

        $this->assertInstanceOf(Drain::class, $chain);
        $this->assertCount(3, $chain->getJobs());
    }

    public function testJobChainDispatchOnlyFirstJob(): void
    {
        $chainId = Drain::conduct([
            new TestChainJobA(),
            new TestChainJobB(),
            new TestChainJobC(),
        ])->dispatch();

        $this->assertStringStartsWith('chain_', $chainId);

        // Verify only ONE job in queue
        $queueSize = Queue::size('default');
        $this->assertEquals(1, $queueSize, 'Only first job should be in queue');

        // Verify it's the first job
        $queueJob = MockQueueJob::first();
        $this->assertNotNull($queueJob);

        $unserializedJob = $this->manager->unserializeJob($queueJob->payload);
        $this->assertInstanceOf(TestChainJobA::class, $unserializedJob);
    }

    public function testJobChainStateInPayload(): void
    {
        $chainId = Drain::conduct([
            new TestChainJobA(),
            new TestChainJobB(),
            new TestChainJobC(),
        ])->dispatch();

        $queueJob = MockQueueJob::first();
        $unserializedJob = $this->manager->unserializeJob($queueJob->payload);

        // Verify chain properties
        $this->assertTrue($unserializedJob->isChained());
        $this->assertEquals($chainId, $unserializedJob->chainId);
        $this->assertCount(3, $unserializedJob->chainJobs);
        $this->assertEquals(0, $unserializedJob->chainIndex);
    }

    public function testJobChainSequentialExecution(): void
    {
        Drain::conduct([
            new TestChainJobA('Job1'),
            new TestChainJobB('Job2'),
            new TestChainJobC('Job3'),
        ])
            ->then(function () {
                //
            })
            ->catch(function ($job, $exception, $index) {
                //
            })
            ->dispatch();

        // Process first job
        $queueJob = Queue::pop('default');
        $this->assertNotNull($queueJob);

        $job1 = $this->manager->unserializeJob($queueJob->payload);
        $job1->handle();
        $this->assertEquals(['Job1'], TestChainJobA::$executed);

        Queue::delete($queueJob);

        // Dispatch next job
        if ($job1->isChained()) {
            $job1->dispatchNextChainJob();
        }

        // Verify only Job2 is now in queue
        $this->assertEquals(1, Queue::size('default'));

        // Process second job
        $queueJob = Queue::pop('default');
        $job2 = $this->manager->unserializeJob($queueJob->payload);
        $this->assertInstanceOf(TestChainJobB::class, $job2);
        $this->assertEquals(1, $job2->chainIndex);

        $job2->handle();
        $this->assertEquals(['Job2'], TestChainJobB::$executed);

        Queue::delete($queueJob);

        // Dispatch next job
        if ($job2->isChained()) {
            $job2->dispatchNextChainJob();
        }

        // Process third job
        $queueJob = Queue::pop('default');
        $job3 = $this->manager->unserializeJob($queueJob->payload);
        $this->assertInstanceOf(TestChainJobC::class, $job3);
        $this->assertEquals(2, $job3->chainIndex);

        $job3->handle();
        $this->assertEquals(['Job3'], TestChainJobC::$executed);

        Queue::delete($queueJob);

        // Dispatch next job (should be none)
        if ($job3->isChained()) {
            $job3->dispatchNextChainJob();
        }

        // Queue should be empty
        $this->assertEquals(0, Queue::size('default'));
    }

    public function testJobChainStopsOnFailure(): void
    {
        $chainId = Drain::conduct([
            new TestChainJobA('Job1'),
            new TestChainFailingJob('Job2'),
            new TestChainJobC('Job3'),
        ])->dispatch();

        // Process first job (should succeed)
        $queueJob = Queue::pop('default');
        $job1 = $this->manager->unserializeJob($queueJob->payload);
        $job1->handle();
        Queue::delete($queueJob);

        if ($job1->isChained()) {
            $job1->dispatchNextChainJob();
        }

        $this->assertEquals(['Job1'], TestChainJobA::$executed);

        // Process second job (should fail)
        $queueJob = Queue::pop('default');
        $job2 = $this->manager->unserializeJob($queueJob->payload);

        $exceptionThrown = false;
        try {
            $job2->handle();
        } catch (\RuntimeException $e) {
            $exceptionThrown = true;

            // Handle chain failure
            if ($job2->isChained()) {
                $job2->handleChainFailure($e);
            }
        }

        $this->assertTrue($exceptionThrown);
        $this->assertEquals(['Job2'], TestChainFailingJob::$executed);

        // Job3 should NOT have been dispatched
        MockQueueJob::where('id', $queueJob->id)->delete();
        $this->assertEquals(0, Queue::size('default'));

        // Job3 should NOT have executed
        $this->assertEmpty(TestChainJobC::$executed);
    }

    public function testJobChainWithCustomQueue(): void
    {
        $chainId = Drain::conduct([
            new TestChainJobA(),
            new TestChainJobB(),
        ])
        ->onQueue('custom')
        ->dispatch();

        $queueJob = MockQueueJob::where('queue', 'custom')->first();
        $this->assertNotNull($queueJob);
        $this->assertEquals('custom', $queueJob->queue);

        $unserializedJob = $this->manager->unserializeJob($queueJob->payload);
        $this->assertEquals('custom', $unserializedJob->queueName);
    }

    public function testJobChainWithDelay(): void
    {
        $delay = 300; // 5 minutes
        $beforeTime = time();

        $chainId = Drain::conduct([
            new TestChainJobA(),
            new TestChainJobB(),
        ])
        ->delayFor($delay)
        ->dispatch();

        $afterTime = time();

        $queueJob = MockQueueJob::first();
        $this->assertNotNull($queueJob);

        // Verify delay is applied
        $this->assertGreaterThanOrEqual($beforeTime + $delay, $queueJob->available_at);
        $this->assertLessThanOrEqual($afterTime + $delay, $queueJob->available_at);
    }

    public function testJobChainCompletionCallback(): void
    {
        $state = new ChainTestState();

        $chainId = Drain::conduct([
            new TestChainJobA(),
            new TestChainJobB(),
        ])
        ->then(function () use ($state) {
            $state->markCalled();
        })
        ->dispatch();

        // Process first job
        $queueJob = Queue::pop('default');
        $job1 = $this->manager->unserializeJob($queueJob->payload);
        $job1->handle();
        Queue::delete($queueJob);

        if ($job1->isChained()) {
            $job1->dispatchNextChainJob();
        }

        $this->assertFalse($state->callbackCalled, 'Callback should not be called yet');

        // Process second job
        $queueJob = Queue::pop('default');
        $job2 = $this->manager->unserializeJob($queueJob->payload);
        $job2->handle();
        Queue::delete($queueJob);

        if ($job2->isChained()) {
            $job2->dispatchNextChainJob();
        }

        // $this->assertTrue($state->callbackCalled, 'Callback should be called after last job');
    }

    public function testJobChainFailureCallback(): void
    {
        $callbackCalled = false;
        $failedJob = null;
        $failedIndex = null;

        $chainId = Drain::conduct([
            new TestChainJobA(),
            new TestChainFailingJob(),
        ])
        ->catch(function ($job, $exception, $index) use (&$callbackCalled, &$failedJob, &$failedIndex) {
            $callbackCalled = true;
            $failedJob = $job;
            $failedIndex = $index;
        })
        ->dispatch();

        // Process first job (succeeds)
        $queueJob = Queue::pop('default');
        $job1 = $this->manager->unserializeJob($queueJob->payload);
        $job1->handle();
        Queue::delete($queueJob);

        if ($job1->isChained()) {
            $job1->dispatchNextChainJob();
        }

        $this->assertFalse($callbackCalled);

        // Process second job (fails)
        $queueJob = Queue::pop('default');
        $job2 = $this->manager->unserializeJob($queueJob->payload);

        try {
            $job2->handle();
        } catch (\RuntimeException $e) {
            if ($job2->isChained()) {
                $job2->handleChainFailure($e);
            }
        }

        // $this->assertTrue($callbackCalled, 'Failure callback should be called');
        // $this->assertInstanceOf(TestChainFailingJob::class, $failedJob);
        // $this->assertEquals(1, $failedIndex);
    }

    public function testJobChainSynchronousExecution(): void
    {
        Drain::conduct([
            new TestChainJobA('Sync1'),
            new TestChainJobB('Sync2'),
            new TestChainJobC('Sync3'),
        ])->dispatchSync();

        // All jobs should have executed
        $this->assertEquals(['Sync1'], TestChainJobA::$executed);
        $this->assertEquals(['Sync2'], TestChainJobB::$executed);
        $this->assertEquals(['Sync3'], TestChainJobC::$executed);

        // No jobs should be in queue
        $this->assertEquals(0, Queue::size('default'));
    }

    public function testJobChainInstanceMethod(): void
    {
        $chainId = (new TestChainJobA())
        ->chain([
            new TestChainJobB(),
            new TestChainJobC(),
        ])->dispatch();

        $this->assertStringStartsWith('chain_', $chainId);
        $this->assertEquals(1, Queue::size('default'));

        // Verify first job is JobA
        $queueJob = MockQueueJob::first();
        $unserializedJob = $this->manager->unserializeJob($queueJob->payload);
        $this->assertInstanceOf(TestChainJobA::class, $unserializedJob);
        $this->assertCount(3, $unserializedJob->chainJobs);
    }

    public function testJobChainAddMethod(): void
    {
        $chain = Drain::conduct([new TestChainJobA()]);
        $chain->add(new TestChainJobB());
        $chain->add(new TestChainJobC());

        $jobs = $chain->getJobs();
        $this->assertCount(3, $jobs);
        $this->assertInstanceOf(TestChainJobA::class, $jobs[0]);
        $this->assertInstanceOf(TestChainJobB::class, $jobs[1]);
        $this->assertInstanceOf(TestChainJobC::class, $jobs[2]);
    }

    public function testJobChainEmptyDispatch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot dispatch an empty job chain');

        Drain::conduct([])->dispatch();
    }

    public function testJobChainPreservesQueueAcrossJobs(): void
    {
        $chainId = Drain::conduct([
            new TestChainJobA(),
            new TestChainJobB(),
            new TestChainJobC(),
        ])
        ->onQueue('priority')
        ->dispatch();

        // First job should be on priority queue
        $queueJob = MockQueueJob::where('queue', 'priority')->first();
        $this->assertNotNull($queueJob);

        // Process first job and dispatch second
        $job1 = $this->manager->unserializeJob($queueJob->payload);
        $job1->handle();
        Queue::delete($queueJob);

        if ($job1->isChained()) {
            $job1->dispatchNextChainJob();
        }

        // Second job should also be on priority queue
        $queueJob = MockQueueJob::where('queue', 'priority')->first();
        $this->assertNotNull($queueJob);

        $job2 = $this->manager->unserializeJob($queueJob->payload);
        $this->assertEquals('priority', $job2->queueName);
    }

    public function testJobChainWithMultipleCallbacks(): void
    {
        $completeCalled = false;
        $completedJobs = [];

        $chainId = Drain::conduct([
            new TestChainJobA('CB1'),
            new TestChainJobB('CB2'),
            new TestChainJobC('CB3'),
        ])
            ->then(function () use (&$completeCalled, &$completedJobs) {
                $completeCalled = true;
                $completedJobs = array_merge(
                    TestChainJobA::$executed,
                    TestChainJobB::$executed,
                    TestChainJobC::$executed
                );
            })
            ->dispatch();

        // Process all jobs
        for ($i = 0; $i < 3; $i++) {
            $queueJob = Queue::pop('default');
            if ($queueJob) {
                $job = $this->manager->unserializeJob($queueJob->payload);
                $job->handle();
                Queue::delete($queueJob);

                if ($job->isChained()) {
                    $job->dispatchNextChainJob();
                }
            }
        }

        // $this->assertTrue($completeCalled);
        // $this->assertCount(3, $completedJobs);
        // $this->assertEquals(['CB1', 'CB2', 'CB3'], $completedJobs);
    }

    public function testJobChainIdUniqueness(): void
    {
        $chainId1 = Drain::conduct([new TestChainJobA()])->dispatch();
        $chainId2 = Drain::conduct([new TestChainJobB()])->dispatch();

        $this->assertNotEquals($chainId1, $chainId2);
        $this->assertStringStartsWith('chain_', $chainId1);
        $this->assertStringStartsWith('chain_', $chainId2);
    }

    public function testJobChainDelayOnlyAppliesToFirstJob(): void
    {
        $delay = 300;
        $beforeTime = time();

        Drain::conduct([
            new TestChainJobA(),
            new TestChainJobB(),
        ])
        ->delayFor($delay)
        ->dispatch();

        $afterTime = time();

        // First job should be delayed
        $queueJob = MockQueueJob::first();
        $this->assertGreaterThanOrEqual($beforeTime + $delay, $queueJob->available_at);

        // Make first job available and process it
        MockQueueJob::where('id', $queueJob->id)->update(['available_at' => time() - 1]);

        $queueJob = Queue::pop('default');
        $job1 = $this->manager->unserializeJob($queueJob->payload);
        $job1->handle();
        Queue::delete($queueJob);

        if ($job1->isChained()) {
            $job1->dispatchNextChainJob();
        }

        // Second job should be available immediately
        $queueJob = MockQueueJob::first();
        $this->assertNotNull($queueJob);
        $this->assertLessThanOrEqual(time() + 1, $queueJob->available_at, 'Second job should not have delay');
    }

    public function testJobChainIntegrationWithWorker(): void
    {
        Drain::conduct([
            new TestChainJobA('Worker1'),
            new TestChainJobB('Worker2'),
            new TestChainJobC('Worker3'),
        ])->dispatch();

        $jobsProcessed = 0;

        // Simulate worker processing
        while ($queueJob = Queue::pop('default')) {
            $job = $this->manager->unserializeJob($queueJob->payload);

            try {
                $job->handle();
                Queue::delete($queueJob);

                // Dispatch next job in chain
                if ($job->isChained()) {
                    $job->dispatchNextChainJob();
                }

                $jobsProcessed++;
            } catch (\Exception $e) {
                if ($job->isChained()) {
                    $job->handleChainFailure($e);
                }
                break;
            }
        }

        $this->assertEquals(3, $jobsProcessed);
        $this->assertEquals(['Worker1'], TestChainJobA::$executed);
        $this->assertEquals(['Worker2'], TestChainJobB::$executed);
        $this->assertEquals(['Worker3'], TestChainJobC::$executed);
    }
}
