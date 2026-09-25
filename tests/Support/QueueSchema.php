<?php

namespace Doppar\Queue\Tests\Support;

use PDO;

/**
 * The queue tables as the package migrations define them, for SQLite tests.
 */
final class QueueSchema
{
    /**
     * The same tables as the migration produces on MySQL.
     */
    public static function createMysql(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE queue_jobs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                queue VARCHAR(255) NOT NULL,
                payload LONGTEXT NOT NULL,
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                priority SMALLINT NOT NULL DEFAULT 0,
                reserved_at INT UNSIGNED NULL,
                lease_expires_at INT UNSIGNED NULL,
                available_at INT UNSIGNED NOT NULL,
                unique_key VARCHAR(191) NULL,
                created_at INT UNSIGNED NOT NULL,
                UNIQUE KEY queue_jobs_unique_key_unique (unique_key),
                KEY idx_queue_jobs_queue (queue),
                KEY idx_queue_jobs_reserved_at (reserved_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE failed_jobs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                connection VARCHAR(255) NOT NULL,
                queue VARCHAR(255) NOT NULL,
                payload LONGTEXT NOT NULL,
                exception LONGTEXT NOT NULL,
                failed_at INT UNSIGNED NOT NULL,
                KEY idx_failed_jobs_failed_at (failed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    /**
     * The same tables as the migration produces on PostgreSQL.
     */
    public static function createPgsql(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE queue_jobs (
                id BIGSERIAL PRIMARY KEY,
                queue VARCHAR(255) NOT NULL,
                payload TEXT NOT NULL,
                attempts SMALLINT NOT NULL DEFAULT 0,
                priority SMALLINT NOT NULL DEFAULT 0,
                reserved_at INTEGER NULL,
                lease_expires_at INTEGER NULL,
                available_at INTEGER NOT NULL,
                unique_key VARCHAR(191) NULL,
                created_at INTEGER NOT NULL
            )
        ");

        $pdo->exec("CREATE UNIQUE INDEX queue_jobs_unique_key_unique ON queue_jobs (unique_key)");
        $pdo->exec("CREATE INDEX idx_queue_jobs_queue ON queue_jobs (queue)");
        $pdo->exec("CREATE INDEX idx_queue_jobs_reserved_at ON queue_jobs (reserved_at)");

        $pdo->exec("
            CREATE TABLE failed_jobs (
                id BIGSERIAL PRIMARY KEY,
                connection VARCHAR(255) NOT NULL,
                queue VARCHAR(255) NOT NULL,
                payload TEXT NOT NULL,
                exception TEXT NOT NULL,
                failed_at INTEGER NOT NULL
            )
        ");

        $pdo->exec("CREATE INDEX idx_failed_jobs_failed_at ON failed_jobs (failed_at)");
    }

    public static function create(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE queue_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                queue TEXT NOT NULL,
                payload TEXT NOT NULL,
                attempts INTEGER DEFAULT 0,
                priority INTEGER NOT NULL DEFAULT 0,
                reserved_at INTEGER,
                lease_expires_at INTEGER,
                available_at INTEGER NOT NULL,
                unique_key TEXT,
                created_at INTEGER NOT NULL
            )
        ");

        $pdo->exec("CREATE INDEX idx_queue_reserved ON queue_jobs(queue, reserved_at)");
        $pdo->exec("CREATE UNIQUE INDEX idx_queue_unique_key ON queue_jobs(unique_key)");

        $pdo->exec("
            CREATE TABLE failed_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                connection TEXT NOT NULL,
                queue TEXT NOT NULL,
                payload TEXT NOT NULL,
                exception TEXT NOT NULL,
                failed_at INTEGER NOT NULL
            )
        ");

        $pdo->exec("CREATE INDEX idx_failed_at ON failed_jobs(failed_at)");
    }
}
