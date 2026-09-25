<?php

namespace Doppar\Queue\Tests\Drivers;

use PDO;
use Doppar\Queue\Contracts\QueueDriver;
use Doppar\Queue\Drivers\DatabaseDriver;
use Doppar\Queue\Support\Envelope;
use Doppar\Queue\Tests\Contract\QueueDriverContract;
use Doppar\Queue\Tests\Support\QueueSchema;
use Phaseolies\Database\Database;

class DatabaseDriverTest extends QueueDriverContract
{
    protected PDO $pdo;

    /**
     * Open a database holding empty queue tables. Engine-specific subclasses override this.
     */
    protected function openDatabase(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        QueueSchema::create($pdo);

        return $pdo;
    }

    protected function isSqlite(): bool
    {
        return true;
    }

    protected function makeDriver(\Closure $clock): QueueDriver
    {
        $this->pdo = $this->openDatabase();

        $this->setConnections(['contract' => $this->pdo]);

        return new DatabaseDriver(['connection' => 'contract', 'lease' => self::LEASE], $clock);
    }

    protected function tearDown(): void
    {
        $this->setConnections([]);
    }

    protected function setConnections(array $connections): void
    {
        (new \ReflectionProperty(Database::class, 'connections'))->setValue(null, $connections);
    }

    public function testClaimingIsACompareAndSwapOnTheAttemptsCounter(): void
    {
        $this->push('a');

        // Another worker claims the row between our SELECT and UPDATE: simulate
        // it by bumping attempts and reserving the row before we pop.
        $this->pdo->exec(
            "UPDATE queue_jobs SET attempts = 1, reserved_at = {$this->now}, lease_expires_at = " . ($this->now + 60)
        );

        $this->assertNull($this->driver->pop('default'), 'a row claimed elsewhere must not be claimed twice');
    }

    public function testRowsWrittenBeforeLeasesExistExpireAfterTheDefaultLease(): void
    {
        // A row reserved by the previous version of the package: reserved_at
        // set, no lease_expires_at column value.
        $this->pdo->exec(
            "INSERT INTO queue_jobs (queue, payload, attempts, reserved_at, lease_expires_at, available_at, created_at)
             VALUES ('default', 'legacy', 1, {$this->now}, NULL, {$this->now}, {$this->now})"
        );

        $this->assertNull($this->driver->pop('default'));

        $this->advance(self::LEASE);

        $job = $this->driver->pop('default');
        $this->assertSame('legacy', $job?->payload);
        $this->assertSame(2, $job->attempts);
    }

    public function testFailIsAtomicWhenTheFailedStoreIsUnavailable(): void
    {
        $this->push('a');
        $job = $this->driver->pop('default');

        $this->pdo->exec('DROP TABLE failed_jobs');

        try {
            $this->driver->fail($job, 'boom');
            $this->fail('fail() should have thrown');
        } catch (\PDOException) {
            // expected
        }

        $stats = $this->driver->stats('default');
        $this->assertSame(1, $stats['reserved'], 'the job must not be lost when it could not be recorded as failed');
    }

    public function testFailJoinsAnOutsideTransactionInsteadOfCommittingIt(): void
    {
        $this->push('a');
        $job = $this->driver->pop('default');

        $this->pdo->beginTransaction();
        $this->driver->fail($job, 'boom');
        $this->pdo->rollBack();

        $this->assertSame(0, $this->driver->countFailed());
        $this->assertSame(1, $this->driver->stats('default')['reserved']);
    }

    public function testDuplicateUniqueKeyDoesNotLeakTheConstraintError(): void
    {
        $this->assertTrue($this->push('a', unique: 'k'));
        $this->assertFalse($this->push('b', unique: 'k'));
        $this->assertSame(1, $this->driver->size('default'));
    }

    public function testConstraintViolationsWithoutAUniqueKeyStillSurface(): void
    {
        if (!$this->isSqlite()) {
            $this->markTestSkipped('Uses SQLite index syntax.');
        }

        $this->pdo->exec('DROP INDEX idx_queue_unique_key');
        $this->pdo->exec('CREATE UNIQUE INDEX idx_queue_payload ON queue_jobs(payload)');

        $this->push('a', payload: 'same');

        $this->expectException(\PDOException::class);
        $this->push('b', payload: 'same');
    }

    public function testOrdinaryPayloadsAreStoredAsPlainReadableText(): void
    {
        $this->push('a', payload: 'a:1:{s:3:"job";s:2:"ok";}');

        $this->assertSame(
            'a:1:{s:3:"job";s:2:"ok";}',
            $this->pdo->query('SELECT payload FROM queue_jobs')->fetchColumn()
        );
    }

    public function testBinaryPayloadsAreStoredEncodedAndReturnedIntact(): void
    {
        $binary = "raw \xFF\xFE bytes \0 and more";

        $this->push('a', payload: $binary);

        $stored = $this->pdo->query('SELECT payload FROM queue_jobs')->fetchColumn();

        $this->assertStringStartsWith('base64:', $stored);
        $this->assertTrue(mb_check_encoding($stored, 'UTF-8'), 'the stored value must fit a text column');
        $this->assertSame($binary, $this->driver->pop('default')?->payload);
    }

    public function testAPayloadThatLooksLikeTheEncodingMarkerIsNotMisread(): void
    {
        $lookalike = 'base64:' . base64_encode('something else');

        $this->push('a', payload: $lookalike);

        $this->assertSame($lookalike, $this->driver->pop('default')?->payload);
    }

    public function testRowsWithPlainPayloadsWrittenByOlderVersionsStillRead(): void
    {
        $this->pdo->exec(
            "INSERT INTO queue_jobs (queue, payload, attempts, available_at, created_at)
             VALUES ('default', 'old plain payload', 0, {$this->now}, {$this->now})"
        );

        $this->assertSame('old plain payload', $this->driver->pop('default')?->payload);
    }

    public function testFailedBinaryPayloadsAreEncodedAndReadBackIntact(): void
    {
        $binary = "bin \xFF\xFE \0";

        $this->push('a', payload: $binary);
        $this->driver->fail($this->driver->pop('default'), 'boom');

        $this->assertSame($binary, $this->driver->failedJobs()[0]->payload);
        $this->assertSame($binary, $this->driver->findFailed($this->driver->failedJobs()[0]->id)?->payload);
    }

    public function testBinaryPayloadsInABulkPushSurvive(): void
    {
        $binary = "bulk \xFF\xFE \0";
        $envelopes = [
            new Envelope('a', 'default', $binary, $this->now, 0, null, $this->now),
            new Envelope('b', 'default', 'plain', $this->now, 0, null, $this->now),
        ];

        $this->driver->pushMany($envelopes);

        $this->assertSame($binary, $this->driver->pop('default')?->payload);
        $this->assertSame('plain', $this->driver->pop('default')?->payload);
    }

    public function testAnExceptionMessageWithUnstorableBytesStillFailsTheJob(): void
    {
        $this->push('a');
        $job = $this->driver->pop('default');

        $this->assertTrue($this->driver->fail($job, "bad \0 byte and \xFF\xFE invalid utf-8"));

        $this->assertSame('bad \\0 byte and ?? invalid utf-8', $this->driver->failedJobs()[0]->exception);
        $this->assertSame(0, $this->driver->stats('default')['reserved'], 'the job must not be left stuck');
    }

    public function testCustomTableNamesAreHonoured(): void
    {
        $this->pdo->exec('ALTER TABLE queue_jobs RENAME TO my_jobs');
        $this->pdo->exec('ALTER TABLE failed_jobs RENAME TO my_failed');

        $driver = new DatabaseDriver([
            'connection' => 'contract',
            'table' => 'my_jobs',
            'failed_table' => 'my_failed',
            'lease' => self::LEASE,
        ], fn(): int => $this->now);

        $driver->push(new Envelope('a', 'default', 'payload-a', $this->now, 0, null, $this->now));
        $driver->fail($driver->pop('default'), 'boom');

        $this->assertSame(1, $driver->countFailed());
    }

    public function testConnectionsAreResolvedOnEveryCallSoReopenedConnectionsAreUsed(): void
    {
        if (!$this->isSqlite()) {
            $this->markTestSkipped('Needs a second empty in-memory database.');
        }

        $this->push('a');

        // The worker drops and reopens connections around forks. Swap the
        // underlying handle for a new one holding the same data.
        $fresh = new PDO('sqlite::memory:');
        $fresh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        QueueSchema::create($fresh);
        $this->setConnections(['contract' => $fresh]);

        $this->assertSame(0, $this->driver->size('default'), 'the driver must not cache the old handle');
    }
}
