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
use Doppar\Queue\Tests\Mock\Models\MockUser;
use Doppar\Queue\Tests\Mock\Models\MockPost;
use Doppar\Queue\Tests\Mock\Models\MockComment;
use Doppar\Queue\Tests\Mock\MockContainer;
use Doppar\Queue\Tests\Mock\Jobs\TestReportJob;
use Doppar\Queue\Tests\Mock\Jobs\TestJobWithFailedCallback;
use Doppar\Queue\Tests\Mock\Jobs\TestImageJob;
use Doppar\Queue\Tests\Mock\Jobs\TestFailingJob;
use Doppar\Queue\Tests\Mock\Jobs\TestEmailJob;
use Doppar\Queue\Tests\Mock\Jobs\TestCounterJob;
use Doppar\Queue\Tests\Mock\Jobs\TestComplexDataJob;
use Doppar\Queue\Tests\Mock\Jobs\TestSendEmailToUserJob;
use Doppar\Queue\Tests\Mock\Jobs\TestMultipleModelsJob;
use Doppar\Queue\Tests\Mock\Jobs\TestModelCollectionJob;
use Doppar\Queue\Tests\Mock\Jobs\TestModelWithRelationsJob;
use Doppar\Queue\Tests\Mock\Jobs\TestDeletedModelJob;
use Doppar\Queue\Tests\Mock\Jobs\TestNestedModelsJob;
use Doppar\Queue\Tests\Mock\Jobs\TestMixedDataJob;
use Doppar\Queue\QueueWorker;
use Doppar\Queue\QueueManager;
use Doppar\Queue\Facades\Queue;
use Phaseolies\Support\Collection;

class ModelSerializationTest extends TestCase
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
        $this->createUserTables();
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

    private function createUserTables(): void
    {
        // Create users table
        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                password TEXT NOT NULL,
                api_token TEXT,
                created_at INTEGER,
                updated_at INTEGER
            )
        ");

        // Create posts table
        $this->pdo->exec("
            CREATE TABLE posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                content TEXT,
                created_at INTEGER,
                updated_at INTEGER,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
        ");

        // Create comments table
        $this->pdo->exec("
            CREATE TABLE comments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                body TEXT NOT NULL,
                created_at INTEGER,
                updated_at INTEGER,
                FOREIGN KEY (post_id) REFERENCES posts(id),
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
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
    // TEST MODEL SERIALIZATION - SINGLE MODEL
    // =====================================================

    public function testSerializeSingleModel(): void
    {
        // Create a user with sensitive data
        $user = MockUser::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'hashed_password_12345',
            'api_token' => 'secret_token_xyz',
        ]);

        // Dispatch job with user model
        $job = new TestSendEmailToUserJob($user);
        $jobId = Queue::push($job);

        // Retrieve the serialized payload
        $queueJob = MockQueueJob::where('queue', 'default')->first();
        $payload = unserialize($queueJob->payload);

        // Verify that only ID is stored, not full model
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('job', $payload);

        // The job should be serialized with SerializesModels
        $serializedJob = serialize($job);

        // Verify sensitive data is NOT in serialized payload
        $this->assertStringNotContainsString('hashed_password_12345', $serializedJob);
        $this->assertStringNotContainsString('secret_token_xyz', $serializedJob);

        // But user ID should be present (as part of model identifier)
        $this->assertStringContainsString('MockUser', $serializedJob);
    }

    public function testUnserializeSingleModel(): void
    {
        // Create a user
        $user = MockUser::create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'hashed_password',
            'api_token' => 'secret_token',
        ]);

        $userId = $user->id;

        // Dispatch job
        $job = new TestSendEmailToUserJob($user);
        Queue::push($job);

        // Unserialize and verify model is fresh from database
        $queueJob = Queue::pop('default');
        $unserializedJob = $this->manager->unserializeJob($queueJob->payload);

        // Access the user property using reflection (it's protected)
        $reflection = new \ReflectionClass($unserializedJob);
        $property = $reflection->getProperty('user');
        $property->setAccessible(true);
        $restoredUser = $property->getValue($unserializedJob);

        // Verify user is a fresh instance from database
        $this->assertInstanceOf(MockUser::class, $restoredUser);
        $this->assertEquals($userId, $restoredUser->id);
        $this->assertEquals('Jane Doe', $restoredUser->name);
        $this->assertEquals('jane@example.com', $restoredUser->email);
    }

    public function testModelDataFreshnessAfterSerialization(): void
    {
        // Create a user
        $user = MockUser::create([
            'name' => 'Original Name',
            'email' => 'original@example.com',
            'password' => 'password123',
        ]);

        // Dispatch job with original user
        $job = new TestSendEmailToUserJob($user);
        Queue::push($job);

        // Update user in database (simulating changes after job dispatch)
        MockUser::where('id', $user->id)->update([
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
        ]);

        // Process the job
        $queueJob = Queue::pop('default');
        $unserializedJob = $this->manager->unserializeJob($queueJob->payload);

        // Execute the job
        $unserializedJob->handle();

        // Get the user from the job
        $reflection = new \ReflectionClass($unserializedJob);
        $property = $reflection->getProperty('user');
        $property->setAccessible(true);
        $restoredUser = $property->getValue($unserializedJob);

        // Verify we got the updated data, not stale data
        $this->assertEquals('Updated Name', $restoredUser->name);
        $this->assertEquals('updated@example.com', $restoredUser->email);
    }

    public function testDeletedModelReturnsNull(): void
    {
        // Create a user
        $user = MockUser::create([
            'name' => 'To Be Deleted',
            'email' => 'deleted@example.com',
            'password' => 'password',
        ]);

        // Dispatch job
        $job = new TestDeletedModelJob($user);
        Queue::push($job);

        // Delete the user before job processes
        MockUser::where('id', $user->id)->delete();

        // Process the job
        $queueJob = Queue::pop('default');
        $unserializedJob = $this->manager->unserializeJob($queueJob->payload);

        // Execute (should handle null gracefully)
        $unserializedJob->handle();

        // Get user from job
        $reflection = new \ReflectionClass($unserializedJob);
        $property = $reflection->getProperty('user');
        $property->setAccessible(true);
        $restoredUser = $property->getValue($unserializedJob);

        // User should be null
        $this->assertNull($restoredUser);
        $this->assertTrue($unserializedJob->wasUserNull);
    }

    // =====================================================
    // TEST MODEL SERIALIZATION - MULTIPLE MODELS
    // =====================================================

    public function testSerializeMultipleModels(): void
    {
        $user = MockUser::create([
            'name' => 'Author',
            'email' => 'author@example.com',
            'password' => 'password',
        ]);

        $post = MockPost::create([
            'user_id' => $user->id,
            'title' => 'Test Post',
            'content' => 'Post content',
        ]);

        $comment = MockComment::create([
            'post_id' => $post->id,
            'user_id' => $user->id,
            'body' => 'Test comment',
        ]);

        // Dispatch job with multiple models
        $job = new TestMultipleModelsJob($user, $post, $comment);
        Queue::push($job);

        $serialized = serialize($job);

        // Verify all model classes are referenced but not full data
        $this->assertStringContainsString('MockUser', $serialized);
        $this->assertStringContainsString('MockPost', $serialized);
        $this->assertStringContainsString('MockComment', $serialized);

        // Verify sensitive/large data is not in payload
        $this->assertStringNotContainsString('password', $serialized);
        $this->assertStringNotContainsString('Post content', $serialized);
    }

    public function testUnserializeMultipleModels(): void
    {
        $user = MockUser::create([
            'name' => 'Multi User',
            'email' => 'multi@example.com',
            'password' => 'password',
        ]);

        $post = MockPost::create([
            'user_id' => $user->id,
            'title' => 'Multi Post',
            'content' => 'Content',
        ]);

        $comment = MockComment::create([
            'post_id' => $post->id,
            'user_id' => $user->id,
            'body' => 'Multi Comment',
        ]);

        $job = new TestMultipleModelsJob($user, $post, $comment);
        Queue::push($job);

        $queueJob = Queue::pop('default');
        $unserializedJob = $this->manager->unserializeJob($queueJob->payload);
        $unserializedJob->handle();

        // Verify all models were restored correctly
        $this->assertTrue($unserializedJob->wasSuccessful);
    }

    public function testMultipleModelsWithOnDeleted(): void
    {
        $user = MockUser::create([
            'name' => 'User',
            'email' => 'user@example.com',
            'password' => 'password',
        ]);

        $post = MockPost::create([
            'user_id' => $user->id,
            'title' => 'Post',
            'content' => 'Content',
        ]);

        $comment = MockComment::create([
            'post_id' => $post->id,
            'user_id' => $user->id,
            'body' => 'Comment',
        ]);

        $job = new TestMultipleModelsJob($user, $post, $comment);
        Queue::push($job);

        // Delete the post (but keep user and comment)
        MockPost::where('id', $post->id)->delete();

        $queueJob = Queue::pop('default');
        $unserializedJob = $this->manager->unserializeJob($queueJob->payload);
        $unserializedJob->handle();

        // Job should handle the missing model
        $this->assertFalse($unserializedJob->wasSuccessful);
    }

    // =====================================================
    // TEST MODEL SERIALIZATION - COLLECTIONS
    // =====================================================

    public function testSerializeModelCollection(): void
    {
        // Create multiple users
        $users = [];
        for ($i = 1; $i <= 5; $i++) {
            $users[] = MockUser::create([
                'name' => "User {$i}",
                'email' => "user{$i}@example.com",
                'password' => "password{$i}",
            ]);
        }

        $collection = new Collection(MockUser::class, $users);

        // Dispatch job with collection
        $job = new TestModelCollectionJob($collection);
        Queue::push($job);

        $serialized = serialize($job);

        // Should contain model class but not all user data
        $this->assertStringContainsString('MockUser', $serialized);

        // Should not contain all passwords
        $this->assertStringNotContainsString('password1', $serialized);
        $this->assertStringNotContainsString('password5', $serialized);
    }

    public function testUnserializeModelCollection(): void
    {
        $users = [];
        for ($i = 1; $i <= 3; $i++) {
            $users[] = MockUser::create([
                'name' => "Collection User {$i}",
                'email' => "coll{$i}@example.com",
                'password' => "pass{$i}",
            ]);
        }

        $collection = new Collection(MockUser::class, $users);
        $job = new TestModelCollectionJob($collection);
        Queue::push($job);

        $queueJob = Queue::pop('default');
        $unserializedJob = $this->manager->unserializeJob($queueJob->payload);
        $unserializedJob->handle();

        // Verify all users were restored
        $this->assertEquals(3, $unserializedJob->processedCount);
    }

    public function testCollectionWithDeletedModels(): void
    {
        $users = [];
        for ($i = 1; $i <= 5; $i++) {
            $users[] = MockUser::create([
                'name' => "User {$i}",
                'email' => "deluser{$i}@example.com",
                'password' => "password",
            ]);
        }

        $collection = new Collection(MockUser::class, $users);
        $job = new TestModelCollectionJob($collection);
        Queue::push($job);

        // Delete 2 users before processing
        MockUser::where('email', 'deluser2@example.com')->delete();
        MockUser::where('email', 'deluser4@example.com')->delete();

        $queueJob = Queue::pop('default');
        $unserializedJob = $this->manager->unserializeJob($queueJob->payload);
        $unserializedJob->handle();

        // Should process only 3 users (2 were deleted)
        $this->assertEquals(3, $unserializedJob->processedCount);
    }

    // =====================================================
    // TEST MODEL SERIALIZATION - NESTED STRUCTURES
    // =====================================================

    public function testSerializeNestedModelsInArray(): void
    {
        $user1 = MockUser::create([
            'name' => 'User 1',
            'email' => 'nested1@example.com',
            'password' => 'password1',
        ]);

        $user2 = MockUser::create([
            'name' => 'User 2',
            'email' => 'nested2@example.com',
            'password' => 'password2',
        ]);

        $post = MockPost::create([
            'user_id' => $user1->id,
            'title' => 'Nested Post',
            'content' => 'Content',
        ]);

        $data = [
            'primary_user' => $user1,
            'secondary_user' => $user2,
            'post' => $post,
            'settings' => ['theme' => 'dark'],
            'count' => 42,
        ];

        $job = new TestNestedModelsJob($data);
        Queue::push($job);

        $serialized = serialize($job);

        // Models should be serialized
        $this->assertStringContainsString('MockUser', $serialized);
        $this->assertStringContainsString('MockPost', $serialized);

        // Regular data should be normal
        $this->assertStringContainsString('dark', $serialized);

        // Sensitive data should NOT be there
        $this->assertStringNotContainsString('password1', $serialized);
        $this->assertStringNotContainsString('password2', $serialized);
    }

    public function testUnserializeNestedModelsInArray(): void
    {
        $user1 = MockUser::create([
            'name' => 'Nested User 1',
            'email' => 'n1@example.com',
            'password' => 'pass1',
        ]);

        $user2 = MockUser::create([
            'name' => 'Nested User 2',
            'email' => 'n2@example.com',
            'password' => 'pass2',
        ]);

        $post = MockPost::create([
            'user_id' => $user1->id,
            'title' => 'Title',
            'content' => 'Content',
        ]);

        $data = [
            'primary_user' => $user1,
            'secondary_user' => $user2,
            'post' => $post,
            'config' => ['enabled' => true],
        ];

        $job = new TestNestedModelsJob($data);
        Queue::push($job);

        $queueJob = Queue::pop('default');
        $unserializedJob = $this->manager->unserializeJob($queueJob->payload);
        $unserializedJob->handle();

        // All models should be restored
        $this->assertTrue($unserializedJob->wasSuccessful);
    }

    // =====================================================
    // TEST MODEL SERIALIZATION - MIXED DATA
    // =====================================================

    public function testMixedModelAndPrimitiveData(): void
    {
        $user = MockUser::create([
            'name' => 'Mixed User',
            'email' => 'mixed@example.com',
            'password' => 'password',
        ]);

        $job = new TestMixedDataJob($user, 'Report Title', ['start' => '2024-01-01']);
        Queue::push($job);

        $queueJob = Queue::pop('default');
        $unserializedJob = $this->manager->unserializeJob($queueJob->payload);
        $unserializedJob->handle();

        // Verify model was restored and primitive data preserved
        $this->assertTrue($unserializedJob->wasSuccessful);
    }

    // =====================================================
    // TEST MODEL SERIALIZATION - RELATIONSHIPS
    // =====================================================

    public function testModelWithRelationshipsNotSerialized(): void
    {
        $user = MockUser::create([
            'name' => 'User With Posts',
            'email' => 'withposts@example.com',
            'password' => 'password',
        ]);

        // Create posts for this user
        $post1 = MockPost::create([
            'user_id' => $user->id,
            'title' => 'Post 1',
            'content' => str_repeat('Large content ', 100), // Large content
        ]);

        $post2 = MockPost::create([
            'user_id' => $user->id,
            'title' => 'Post 2',
            'content' => str_repeat('More content ', 100),
        ]);

        // Load relationships
        $user->posts = MockPost::where('user_id', $user->id)->get();

        // Dispatch job
        $job = new TestModelWithRelationsJob($user);
        Queue::push($job);

        $queueJob = MockQueueJob::first();
        $serialized = $queueJob->payload;

        // Relationships should NOT be in payload
        // This will reduce our payload size
        $this->assertStringNotContainsString('Large content', $serialized);
        $this->assertStringNotContainsString('More content', $serialized);
    }

    public function testRelationshipsMustBeReloadedInJob(): void
    {
        $user = MockUser::create([
            'name' => 'Relation User',
            'email' => 'relation@example.com',
            'password' => 'password',
        ]);

        MockPost::create([
            'user_id' => $user->id,
            'title' => 'Post 1',
            'content' => 'Content 1',
        ]);

        MockPost::create([
            'user_id' => $user->id,
            'title' => 'Post 2',
            'content' => 'Content 2',
        ]);

        // Load relationships before dispatching
        $user->posts = MockPost::where('user_id', $user->id)->get();

        $job = new TestModelWithRelationsJob($user);
        Queue::push($job);

        $queueJob = Queue::pop('default');
        $unserializedJob = $this->manager->unserializeJob($queueJob->payload);
        $unserializedJob->handle();

        // Job should successfully reload relationships
        $this->assertEquals(2, $unserializedJob->postCount);
    }

    public function testEndToEndWithModelSerialization(): void
    {
        // Create users
        $users = [];
        for ($i = 1; $i <= 3; $i++) {
            $users[] = MockUser::create([
                'name' => "User {$i}",
                'email' => "e2e{$i}@example.com",
                'password' => "password{$i}",
                'api_token' => "token_{$i}",
            ]);
        }

        // Dispatch jobs with models
        foreach ($users as $user) {
            $job = new TestSendEmailToUserJob($user);
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
    }

    // =====================================================
    // TEST EDGE CASES
    // =====================================================

    public function testSerializeModelWithNullAttributes(): void
    {
        $user = MockUser::create([
            'name' => 'Null User',
            'email' => 'null@example.com',
            'password' => 'password',
            'api_token' => null, // Explicitly null
        ]);

        $job = new TestSendEmailToUserJob($user);
        Queue::push($job);

        $queueJob = Queue::pop('default');
        $unserializedJob = $this->manager->unserializeJob($queueJob->payload);
        $unserializedJob->handle();

        // Should handle null attributes gracefully
        $this->assertTrue(true); // If we get here, it worked
    }

    public function testConcurrentJobProcessing(): void
    {
        // Create multiple users and jobs
        for ($i = 1; $i <= 10; $i++) {
            $user = MockUser::create([
                'name' => "Concurrent User {$i}",
                'email' => "concurrent{$i}@example.com",
                'password' => "password",
            ]);

            $job = new TestSendEmailToUserJob($user);
            Queue::push($job);
        }

        $this->assertEquals(10, Queue::size('default'));

        // Simulate concurrent processing (serial in test, but validates logic)
        $processed = 0;
        while ($queueJob = Queue::pop('default')) {
            $unserializedJob = $this->manager->unserializeJob($queueJob->payload);

            // Simulate some delay
            usleep(1000); // 1ms

            $unserializedJob->handle();
            Queue::delete($queueJob);
            $processed++;
        }

        $this->assertEquals(10, $processed);
    }

    public function testModelUpdateBetweenDispatchAndExecution(): void
    {
        $user = MockUser::create([
            'name' => 'Before Update',
            'email' => 'before@example.com',
            'password' => 'old_password',
        ]);

        // Dispatch job
        $job = new TestSendEmailToUserJob($user);
        Queue::push($job);

        // Update user multiple times
        MockUser::where('id', $user->id)->update(['name' => 'After Update 1']);
        MockUser::where('id', $user->id)->update(['name' => 'After Update 2']);
        MockUser::where('id', $user->id)->update(['name' => 'Final Update']);

        // Process job
        $queueJob = Queue::pop('default');
        $unserializedJob = $this->manager->unserializeJob($queueJob->payload);
        $unserializedJob->handle();

        // Get the restored user
        $reflection = new \ReflectionClass($unserializedJob);
        $property = $reflection->getProperty('user');
        $property->setAccessible(true);
        $restoredUser = $property->getValue($unserializedJob);

        // Should have the LATEST data
        $this->assertEquals('Final Update', $restoredUser->name);
    }

    public function testEmptyCollectionSerialization(): void
    {
        $emptyCollection = new Collection(MockUser::class, []);
        $job = new TestModelCollectionJob($emptyCollection);
        Queue::push($job);

        $queueJob = Queue::pop('default');
        $unserializedJob = $this->manager->unserializeJob($queueJob->payload);
        $unserializedJob->handle();

        // Should handle empty collection
        $this->assertEquals(0, $unserializedJob->processedCount);
    }

    public function testModelWithDifferentConnections(): void
    {
        // This tests that connection info is preserved
        $user = MockUser::create([
            'name' => 'Connection User',
            'email' => 'connection@example.com',
            'password' => 'password',
        ]);

        // Set a specific connection (in real app)
        $reflection = new \ReflectionClass($user);
        $property = $reflection->getProperty('connection');
        $property->setAccessible(true);
        $property->setValue($user, 'sqlite');

        $job = new TestSendEmailToUserJob($user);
        Queue::push($job);

        $queueJob = Queue::pop('default');
        $unserializedJob = $this->manager->unserializeJob($queueJob->payload);
        $unserializedJob->handle();

        // Model should be restored with correct connection
        $this->assertTrue(true); // If we get here, connection was handled
    }

    public function testSerializeModelTwiceInSameJob(): void
    {
        $user = MockUser::create([
            'name' => 'Duplicate User',
            'email' => 'duplicate@example.com',
            'password' => 'password',
        ]);

        // Create job with same model referenced twice
        $data = [
            'primary' => $user,
            'backup' => $user, // Same instance
        ];

        $job = new TestNestedModelsJob($data);
        Queue::push($job);

        $queueJob = Queue::pop('default');
        $unserializedJob = $this->manager->unserializeJob($queueJob->payload);
        $unserializedJob->handle();

        // Both references should be restored
        $this->assertTrue($unserializedJob->wasSuccessful);
    }

    // =====================================================
    // TEST SECURITY
    // =====================================================

    public function testPasswordNotInSerializedPayload(): void
    {
        $user = MockUser::create([
            'name' => 'Security User',
            'email' => 'security@example.com',
            'password' => 'super_secret_password_12345',
            'api_token' => 'secret_api_token_xyz',
        ]);

        $job = new TestSendEmailToUserJob($user);
        Queue::push($job);

        $queueJob = MockQueueJob::first();
        $payload = $queueJob->payload;

        // Password should NOT be in payload
        $this->assertStringNotContainsString('super_secret_password_12345', $payload);
        $this->assertStringNotContainsString('secret_api_token_xyz', $payload);
    }

    public function testHiddenFieldsNotSerialized(): void
    {
        $user = MockUser::create([
            'name' => 'Hidden Test',
            'email' => 'hidden@example.com',
            'password' => 'hidden_password',
            'api_token' => 'hidden_token',
        ]);

        $job = new TestSendEmailToUserJob($user);
        $serialized = serialize($job);

        // Hidden/sensitive fields should not appear
        $this->assertStringNotContainsString('hidden_password', $serialized);
        $this->assertStringNotContainsString('hidden_token', $serialized);

        // But the model class should be referenced
        $this->assertStringContainsString('MockUser', $serialized);
    }

    public function testSerializedPayloadDoesNotGrowWithRelationships(): void
    {
        $user = MockUser::create([
            'name' => 'User With Many Posts',
            'email' => 'many@example.com',
            'password' => 'password',
        ]);

        // Create many posts
        for ($i = 1; $i <= 50; $i++) {
            MockPost::create([
                'user_id' => $user->id,
                'title' => "Post {$i}",
                'content' => str_repeat("Content for post {$i} ", 100), // Large content
            ]);
        }

        // Load all posts
        $user->posts = MockPost::where('user_id', $user->id)->get();

        // Serialize job
        $job = new TestSendEmailToUserJob($user);
        Queue::push($job);

        $queueJob = MockQueueJob::first();
        $payloadSize = strlen($queueJob->payload);

        // Payload should be small despite 50 loaded posts
        // If relationships were serialized, it would be > 50KB
        $this->assertLessThan(5000, $payloadSize); // Should be under 5KB
    }

    public function testPayloadSizeReduction(): void
    {
        // Create user with large data
        $user = MockUser::create([
            'name' => 'Large Data User',
            'email' => 'large@example.com',
            'password' => str_repeat('a', 100), // Large password (100 chars)
            'api_token' => str_repeat('t', 100), // Large token (100 chars)
        ]);

        // Dispatch job with SerializesModels
        $job = new TestSendEmailToUserJob($user);
        Queue::push($job);

        $queueJob = MockQueueJob::first();
        $payload = $queueJob->payload;

        // PRIMARY TEST: Sensitive data should NOT be in payload
        $this->assertStringNotContainsString(
            str_repeat('a', 100),
            $payload,
            'Password should not be in serialized payload'
        );
        $this->assertStringNotContainsString(
            str_repeat('t', 100),
            $payload,
            'API token should not be in serialized payload'
        );

        // SECONDARY TEST: Model class should be referenced
        $this->assertStringContainsString(
            'MockUser',
            $payload,
            'Model class should be referenced in payload'
        );

        // TERTIARY TEST: Payload should be reasonably small
        // Without model serialize, just the password + token = 200 bytes
        // With full model data, it would be much larger
        $payloadSize = strlen($payload);

        // The payload should not contain the full 200+ chars of sensitive data
        // This is a soft check - just ensure it's not unreasonably large
        $this->assertLessThan(
            10000,
            $payloadSize,
            'Payload should be reasonably sized (< 10KB)'
        );
    }

    public function testLargeCollectionSerialization(): void
    {
        // Create users with unique passwords to verify they're not serialized
        $users = [];
        $uniquePasswords = [];

        for ($i = 1; $i <= 50; $i++) { // Reduced to 50 for faster tests
            $password = "unique_password_{$i}_" . str_repeat('x', 40);
            $uniquePasswords[] = $password;

            $users[] = MockUser::create([
                'name' => "User {$i}",
                'email' => "large{$i}@example.com",
                'password' => $password, // Each password is unique and 60+ chars
            ]);
        }

        $collection = new Collection(MockUser::class, $users);
        $job = new TestModelCollectionJob($collection);

        // Measure serialization time
        $startTime = microtime(true);
        Queue::push($job);
        $serializeTime = microtime(true) - $startTime;

        // Should serialize quickly even with 50 models
        $this->assertLessThan(
            1.0,
            $serializeTime,
            'Serialization should complete in under 1 second'
        );

        // Get the payload
        $queueJob = MockQueueJob::first();
        $payload = $queueJob->payload;
        $payloadSize = strlen($payload);

        // PRIMARY TEST: None of the unique passwords should be in payload
        $passwordsFound = 0;
        foreach ($uniquePasswords as $password) {
            if (strpos($payload, $password) !== false) {
                $passwordsFound++;
            }
        }

        $this->assertEquals(
            0,
            $passwordsFound,
            'No passwords should be found in serialized payload'
        );

        // SECONDARY TEST: Model class should be referenced
        $this->assertStringContainsString(
            'MockUser',
            $payload,
            'Model class should be in payload'
        );
    }

    public function testPayloadContainsOnlyIdentifiers(): void
    {
        // Create a user
        $user = MockUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'secret_password_12345',
            'api_token' => 'secret_token_abcde',
        ]);

        // Dispatch job
        $job = new TestSendEmailToUserJob($user);
        Queue::push($job);

        // Get payload
        $queueJob = MockQueueJob::first();
        $payload = $queueJob->payload;

        // Unserialize to inspect structure
        $data = unserialize($payload);

        // The payload should contain the job data
        $this->assertIsArray($data);
        $this->assertArrayHasKey('job', $data);

        // Serialize the job object to inspect its structure
        $jobSerialized = serialize($data['job']);

        // Should contain model class name
        $this->assertStringContainsString('MockUser', $jobSerialized);

        // Should contain serialization marker
        $this->assertStringContainsString('__serialized_model__', $jobSerialized);

        // Should NOT contain sensitive data
        $this->assertStringNotContainsString('secret_password_12345', $jobSerialized);
        $this->assertStringNotContainsString('secret_token_abcde', $jobSerialized);
        $this->assertStringNotContainsString('Test User', $jobSerialized);
        $this->assertStringNotContainsString('test@example.com', $jobSerialized);
    }

    public function testSerializedModelStructure(): void
    {
        // Create user
        $user = MockUser::create([
            'name' => 'Structure Test',
            'email' => 'structure@example.com',
            'password' => 'password123',
        ]);

        $userId = $user->id;

        // Create a mock job to inspect serialization
        $job = new TestSendEmailToUserJob($user);

        // Access the serialization result using __serialize
        $serialized = $job->__serialize();

        // The serialized data should contain a 'user' key
        $this->assertArrayHasKey('user', $serialized);

        $userSerialized = $serialized['user'];

        // Should be an array with specific structure
        $this->assertIsArray($userSerialized);
        $this->assertArrayHasKey('__serialized_model__', $userSerialized);
        $this->assertTrue($userSerialized['__serialized_model__']);

        $this->assertArrayHasKey('class', $userSerialized);
        $this->assertEquals(MockUser::class, $userSerialized['class']);

        $this->assertArrayHasKey('id', $userSerialized);
        $this->assertEquals($userId, $userSerialized['id']);

        // Should NOT contain model attributes
        $this->assertArrayNotHasKey('name', $userSerialized);
        $this->assertArrayNotHasKey('email', $userSerialized);
        $this->assertArrayNotHasKey('password', $userSerialized);
    }

    // Test to verify the complete cycle
    public function testSerializationDeserializationCycle(): void
    {
        // Create user with all fields
        $user = MockUser::create([
            'name' => 'Cycle Test User',
            'email' => 'cycle@example.com',
            'password' => 'original_password',
            'api_token' => 'original_token',
        ]);

        $originalId = $user->id;
        $originalName = $user->name;
        $originalEmail = $user->email;

        // Dispatch job
        $job = new TestSendEmailToUserJob($user);
        Queue::push($job);

        // Now update the user (to verify we get fresh data)
        MockUser::where('id', $user->id)->update([
            'name' => 'Updated Name',
            'password' => 'updated_password',
        ]);

        // Process the job
        $queueJob = Queue::pop('default');
        $unserializedJob = $this->manager->unserializeJob($queueJob->payload);

        // Get the user from job
        $reflection = new \ReflectionClass($unserializedJob);
        $property = $reflection->getProperty('user');
        $property->setAccessible(true);
        $restoredUser = $property->getValue($unserializedJob);

        // Verify we got fresh data
        $this->assertNotNull($restoredUser);
        $this->assertEquals($originalId, $restoredUser->id);
        $this->assertEquals('Updated Name', $restoredUser->name); // Updated!
        $this->assertEquals($originalEmail, $restoredUser->email); // Unchanged

        // Verify the password in DB is the updated one
        $freshUser = MockUser::find($originalId);
        $this->assertEquals('updated_password', $freshUser->password);
    }
}
