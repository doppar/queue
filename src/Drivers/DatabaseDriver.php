<?php

namespace Doppar\Queue\Drivers;

use PDO;
use PDOException;
use Doppar\Queue\Support\Envelope;
use Doppar\Queue\Support\FailedJobRecord;
use Doppar\Queue\Support\ReservedJob;
use Phaseolies\Database\Database;

class DatabaseDriver extends BaseDriver
{
    /**
     * How many candidate rows a pop tries before giving up on this queue,
     * each attempt losing a race to another worker.
     */
    private const CLAIM_ATTEMPTS = 8;

    /**
     * Rows per multi-row INSERT in pushMany
     */
    private const INSERT_CHUNK = 200;

    /**
     * Prefix of a payload stored base64-encoded because its column could not hold it as is
     */
    private const ENCODED_PREFIX = 'base64:';

    /**
     * @inheritDoc
     */
    public function push(Envelope $envelope): bool
    {
        try {
            $statement = $this->pdo()->prepare(
                "INSERT INTO {$this->table()} "
                . '(queue, payload, attempts, priority, reserved_at, lease_expires_at, available_at, unique_key, created_at) '
                . 'VALUES (?, ?, 0, ?, NULL, NULL, ?, ?, ?)'
            );

            $this->run($statement, $this->row($envelope));

            return true;
        } catch (PDOException $e) {
            if ($envelope->uniqueKey !== null && $this->isConstraintViolation($e)) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function pushMany(array $envelopes): int
    {
        $stored = 0;
        $chunk = [];

        foreach ($envelopes as $envelope) {
            // A unique job goes through push() so a duplicate is skipped on
            // its own instead of failing the whole multi-row insert.
            if ($envelope->uniqueKey !== null) {
                $stored += $this->insertChunk($chunk);
                $chunk = [];
                $stored += $this->push($envelope) ? 1 : 0;
                continue;
            }

            $chunk[] = $envelope;

            if (count($chunk) >= self::INSERT_CHUNK) {
                $stored += $this->insertChunk($chunk);
                $chunk = [];
            }
        }

        return $stored + $this->insertChunk($chunk);
    }

    /**
     * @inheritDoc
     */
    public function pop(string|array $queues, ?int $leaseFor = null): ?ReservedJob
    {
        $now = $this->now();
        $lease = $this->leaseSeconds($leaseFor);
        $default = (int) ($this->config['lease'] ?? self::DEFAULT_LEASE);
        $pdo = $this->pdo();

        // A job is claimable when unreserved, or when its lease ran out. Rows
        // written before leases existed have no lease_expires_at, so they
        // expire a default lease after they were reserved.
        $claimable = '(reserved_at IS NULL OR reserved_at = 0 OR COALESCE(lease_expires_at, reserved_at + ?) <= ?)';

        $select = $pdo->prepare(
            "SELECT id, payload, attempts FROM {$this->table()} "
            . "WHERE queue = ? AND available_at <= ? AND {$claimable} "
            . 'ORDER BY priority DESC, id ASC LIMIT 1'
        );

        $claim = $pdo->prepare(
            "UPDATE {$this->table()} SET reserved_at = ?, lease_expires_at = ?, attempts = attempts + 1 "
            . "WHERE id = ? AND attempts = ? AND {$claimable}"
        );

        foreach ($this->queueList($queues) as $queue) {
            for ($try = 0; $try < self::CLAIM_ATTEMPTS; $try++) {
                $this->run($select, [$queue, $now, $default, $now]);
                $row = $select->fetch(PDO::FETCH_ASSOC);
                $select->closeCursor();

                if ($row === false) {
                    break;
                }

                $this->run($claim, [$now, $now + $lease, (int) $row['id'], (int) $row['attempts'], $default, $now]);

                if ($claim->rowCount() === 1) {
                    return new ReservedJob(
                        (int) $row['id'],
                        $queue,
                        $this->decodePayload($row['payload']),
                        (int) $row['attempts'] + 1,
                        $now,
                        $now + $lease
                    );
                }

                // Another worker claimed this row first; try the next candidate.
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function delete(ReservedJob $job): bool
    {
        $statement = $this->pdo()->prepare(
            "DELETE FROM {$this->table()} WHERE id = ? AND attempts = ? AND reserved_at IS NOT NULL"
        );
        $this->run($statement, [$job->id, $job->attempts]);

        return $statement->rowCount() === 1;
    }

    /**
     * @inheritDoc
     */
    public function release(ReservedJob $job, int $delay = 0): bool
    {
        $statement = $this->pdo()->prepare(
            "UPDATE {$this->table()} SET reserved_at = NULL, lease_expires_at = NULL, available_at = ? "
            . 'WHERE id = ? AND attempts = ? AND reserved_at IS NOT NULL'
        );
        $this->run($statement, [$this->now() + max(0, $delay), $job->id, $job->attempts]);

        return $statement->rowCount() === 1;
    }

    /**
     * @inheritDoc
     */
    public function extend(ReservedJob $job, int $seconds): bool
    {
        $statement = $this->pdo()->prepare(
            "UPDATE {$this->table()} SET lease_expires_at = ? "
            . 'WHERE id = ? AND attempts = ? AND reserved_at IS NOT NULL'
        );
        $this->run($statement, [$this->now() + $seconds, $job->id, $job->attempts]);

        return $statement->rowCount() === 1;
    }

    /**
     * @inheritDoc
     */
    public function fail(ReservedJob $job, string $exception): bool
    {
        $pdo = $this->pdo();

        // Join a transaction the caller already opened; otherwise own one.
        $opened = !$pdo->inTransaction();

        if ($opened) {
            $pdo->beginTransaction();
        }

        try {
            $select = $pdo->prepare(
                "SELECT queue, payload FROM {$this->table()} WHERE id = ? AND attempts = ? AND reserved_at IS NOT NULL"
            );
            $this->run($select, [$job->id, $job->attempts]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            $select->closeCursor();

            $delete = $pdo->prepare(
                "DELETE FROM {$this->table()} WHERE id = ? AND attempts = ? AND reserved_at IS NOT NULL"
            );
            $this->run($delete, [$job->id, $job->attempts]);

            if ($row === false || $delete->rowCount() !== 1) {
                if ($opened) {
                    $opened = false;
                    $pdo->rollBack();
                }

                return false;
            }

            $this->run($pdo->prepare(
                "INSERT INTO {$this->failedTable()} (connection, queue, payload, exception, failed_at) VALUES (?, ?, ?, ?, ?)"
            ), [
                $this->config['name'] ?? 'database',
                $row['queue'],
                $row['payload'],
                $this->storableText($exception),
                $this->now(),
            ]);

            if ($opened) {
                $opened = false;
                $pdo->commit();
            }

            return true;
        } catch (\Throwable $e) {
            if ($opened) {
                $pdo->rollBack();
            }

            throw $e;
        }
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
        $now = $this->now();
        $default = (int) ($this->config['lease'] ?? self::DEFAULT_LEASE);

        $statement = $this->pdo()->prepare(
            "SELECT
                SUM(CASE WHEN (reserved_at IS NULL OR reserved_at = 0 OR COALESCE(lease_expires_at, reserved_at + ?) <= ?) AND available_at <= ? THEN 1 ELSE 0 END) AS ready_count,
                SUM(CASE WHEN (reserved_at IS NULL OR reserved_at = 0 OR COALESCE(lease_expires_at, reserved_at + ?) <= ?) AND available_at > ? THEN 1 ELSE 0 END) AS delayed_count,
                SUM(CASE WHEN NOT (reserved_at IS NULL OR reserved_at = 0 OR COALESCE(lease_expires_at, reserved_at + ?) <= ?) THEN 1 ELSE 0 END) AS reserved_count
             FROM {$this->table()} WHERE queue = ?"
        );
        $this->run($statement, [$default, $now, $now, $default, $now, $now, $default, $now, $queue]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'ready' => (int) ($row['ready_count'] ?? 0),
            'delayed' => (int) ($row['delayed_count'] ?? 0),
            'reserved' => (int) ($row['reserved_count'] ?? 0),
        ];
    }

    /**
     * @inheritDoc
     */
    public function queues(): array
    {
        $names = $this->query("SELECT DISTINCT queue FROM {$this->table()} ORDER BY queue")
            ->fetchAll(PDO::FETCH_COLUMN);

        return array_map('strval', $names);
    }

    /**
     * @inheritDoc
     */
    public function clear(string $queue): int
    {
        $statement = $this->pdo()->prepare("DELETE FROM {$this->table()} WHERE queue = ?");
        $this->run($statement, [$queue]);

        return $statement->rowCount();
    }

    /**
     * @inheritDoc
     */
    public function failedJobs(): array
    {
        $rows = $this->query(
            "SELECT id, connection, queue, payload, exception, failed_at FROM {$this->failedTable()} ORDER BY failed_at DESC, id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map($this->failedRecord(...), $rows);
    }

    /**
     * @inheritDoc
     */
    public function findFailed(string|int $id): ?FailedJobRecord
    {
        $statement = $this->pdo()->prepare(
            "SELECT id, connection, queue, payload, exception, failed_at FROM {$this->failedTable()} WHERE id = ?"
        );
        $this->run($statement, [(int) $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->failedRecord($row);
    }

    /**
     * @inheritDoc
     */
    public function forgetFailed(string|int $id): bool
    {
        $statement = $this->pdo()->prepare("DELETE FROM {$this->failedTable()} WHERE id = ?");
        $this->run($statement, [(int) $id]);

        return $statement->rowCount() === 1;
    }

    /**
     * @inheritDoc
     */
    public function flushFailed(): int
    {
        return (int) $this->pdo()->exec("DELETE FROM {$this->failedTable()}");
    }

    /**
     * @inheritDoc
     */
    public function countFailed(): int
    {
        return (int) $this->query("SELECT COUNT(*) FROM {$this->failedTable()}")->fetchColumn();
    }

    /**
     * Run a query that has no parameters.
     *
     * @param string $sql
     * @return \PDOStatement
     * @throws PDOException
     */
    private function query(string $sql): \PDOStatement
    {
        $statement = $this->pdo()->query($sql);

        if ($statement === false) {
            throw new PDOException("Queue query failed: {$sql}");
        }

        return $statement;
    }

    /**
     * Execute a prepared statement with typed bindings.
     *
     * Integers must be bound as integers: PDO binds everything as a string by
     * default, and SQLite orders any integer before any string, so a comparison
     * such as `COALESCE(lease_expires_at, ...) <= ?` would silently misbehave.
     *
     * @param \PDOStatement $statement
     * @param array<int, mixed> $params
     * @return \PDOStatement
     */
    private function run(\PDOStatement $statement, array $params): \PDOStatement
    {
        foreach (array_values($params) as $index => $value) {
            $statement->bindValue($index + 1, $value, match (true) {
                is_int($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            });
        }

        $statement->execute();

        return $statement;
    }

    /**
     * Insert several non-unique envelopes with one statement
     *
     * @param array<int, Envelope> $envelopes
     * @return int
     */
    private function insertChunk(array $envelopes): int
    {
        if ($envelopes === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($envelopes), '(?, ?, 0, ?, NULL, NULL, ?, ?, ?)'));

        $statement = $this->pdo()->prepare(
            "INSERT INTO {$this->table()} "
            . '(queue, payload, attempts, priority, reserved_at, lease_expires_at, available_at, unique_key, created_at) '
            . "VALUES {$placeholders}"
        );

        $this->run($statement, array_merge(...array_map($this->row(...), $envelopes)));

        return count($envelopes);
    }

    /**
     * Bind values for one row of the insert column list
     *
     * @param Envelope $envelope
     * @return array<int, mixed>
     */
    private function row(Envelope $envelope): array
    {
        return [
            $envelope->queue,
            $this->encodePayload($envelope->payload),
            $envelope->priority,
            $envelope->availableAt,
            $envelope->uniqueKey,
            $envelope->createdAt ?: $this->now(),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return FailedJobRecord
     */
    private function failedRecord(array $row): FailedJobRecord
    {
        return new FailedJobRecord(
            (int) $row['id'],
            (string) $row['connection'],
            (string) $row['queue'],
            $this->decodePayload((string) $row['payload']),
            (string) $row['exception'],
            (int) $row['failed_at']
        );
    }

    /**
     * Prepare a payload for a text column.
     *
     * @param string $payload
     * @return string
     */
    private function encodePayload(string $payload): string
    {
        $needsEncoding = str_starts_with($payload, self::ENCODED_PREFIX)
            || !mb_check_encoding($payload, 'UTF-8')
            || (str_contains($payload, "\0") && $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql');

        return $needsEncoding ? self::ENCODED_PREFIX . base64_encode($payload) : $payload;
    }

    /**
     * Make diagnostic text safe for a text column
     *
     * @param string $text
     * @return string
     */
    private function storableText(string $text): string
    {
        $text = str_replace("\0", '\\0', $text);

        return mb_check_encoding($text, 'UTF-8') ? $text : mb_scrub($text, 'UTF-8');
    }

    /**
     * Reverse encodePayload()
     *
     * @param string $stored
     * @return string
     */
    private function decodePayload(string $stored): string
    {
        if (!str_starts_with($stored, self::ENCODED_PREFIX)) {
            return $stored;
        }

        return (string) base64_decode(substr($stored, strlen(self::ENCODED_PREFIX)), true);
    }

    /**
     * SQLSTATE class 23 covers integrity constraint violations
     *
     * @param PDOException $e
     * @return bool
     */
    private function isConstraintViolation(PDOException $e): bool
    {
        return str_starts_with((string) $e->getCode(), '23');
    }

    /**
     * Fetched fresh on every call: the worker drops and reopens database
     * connections around forks, so a cached handle could be a dead one.
     *
     * @return PDO
     */
    private function pdo(): PDO
    {
        return Database::getPdoInstance($this->config['connection'] ?? null);
    }

    /**
     * Get the queue job table
     *
     * @return string
     */
    private function table(): string
    {
        return (string) ($this->config['table'] ?? 'queue_jobs');
    }

    /**
     * Get the failed job table
     *
     * @return string
     */
    private function failedTable(): string
    {
        return (string) ($this->config['failed_table'] ?? 'failed_jobs');
    }
}
