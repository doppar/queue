<?php

namespace Doppar\Queue\Tests\Drivers;

use PDO;
use Doppar\Queue\Tests\Support\QueueSchema;

/**
 * The database driver contract on MySQL. Opt in by pointing it at a scratch
 * database:
 *
 *   QUEUE_TEST_MYSQL_DSN  mysql:host=127.0.0.1;dbname=queue_test;charset=utf8mb4
 *   QUEUE_TEST_MYSQL_USER
 *   QUEUE_TEST_MYSQL_PASS
 *
 * It DROPS and recreates the queue tables, so it refuses to run unless the
 * database name contains "test" or "scratch".
 */
class MysqlDatabaseDriverTest extends DatabaseDriverTest
{
    protected function isSqlite(): bool
    {
        return false;
    }

    protected function openDatabase(): PDO
    {
        $dsn = getenv('QUEUE_TEST_MYSQL_DSN');

        if (!$dsn) {
            $this->markTestSkipped('Set QUEUE_TEST_MYSQL_DSN to run the MySQL driver tests.');
        }

        try {
            $pdo = new PDO($dsn, getenv('QUEUE_TEST_MYSQL_USER') ?: null, getenv('QUEUE_TEST_MYSQL_PASS') ?: null);
        } catch (\PDOException $e) {
            $this->markTestSkipped('MySQL is not reachable: ' . $e->getMessage());
        }

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if (!preg_match('/test|scratch/i', $database)) {
            $this->markTestSkipped("Refusing to drop tables in [{$database}]: its name must contain \"test\" or \"scratch\".");
        }

        $this->dropTables($pdo);
        QueueSchema::createMysql($pdo);

        return $pdo;
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->dropTables($this->pdo);
        }

        parent::tearDown();
    }

    private function dropTables(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS queue_jobs, failed_jobs, my_jobs, my_failed');
    }
}
