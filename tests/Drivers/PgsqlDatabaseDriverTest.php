<?php

namespace Doppar\Queue\Tests\Drivers;

use PDO;
use Doppar\Queue\Tests\Support\NeedsBackend;
use Doppar\Queue\Tests\Support\QueueSchema;

/**
 * The database driver contract on PostgreSQL. Opt in with:
 *
 *   QUEUE_TEST_PGSQL_DSN     pgsql:host=127.0.0.1;dbname=app
 *   QUEUE_TEST_PGSQL_USER
 *   QUEUE_TEST_PGSQL_PASS
 *   QUEUE_TEST_PGSQL_SCHEMA  a schema whose name contains "test" or "scratch"
 *
 * The tests run inside that schema only: search_path is pinned to it, and the
 * queue tables in it are DROPPED and recreated. It refuses to run against any
 * other schema, so tables in "public" are never touched.
 */
class PgsqlDatabaseDriverTest extends DatabaseDriverTest
{
    use NeedsBackend;

    protected function isSqlite(): bool
    {
        return false;
    }

    protected function openDatabase(): PDO
    {
        $dsn = getenv('QUEUE_TEST_PGSQL_DSN');
        $schema = (string) getenv('QUEUE_TEST_PGSQL_SCHEMA');

        if (!$dsn || $schema === '') {
            $this->backendUnavailable('Set QUEUE_TEST_PGSQL_DSN and QUEUE_TEST_PGSQL_SCHEMA to run the PostgreSQL driver tests.');
        }

        if (!preg_match('/^[a-z0-9_]*(test|scratch)[a-z0-9_]*$/i', $schema)) {
            $this->backendUnavailable("Refusing schema [{$schema}]: its name must contain \"test\" or \"scratch\".");
        }

        try {
            $pdo = new PDO($dsn, getenv('QUEUE_TEST_PGSQL_USER') ?: null, getenv('QUEUE_TEST_PGSQL_PASS') ?: null);
        } catch (\PDOException $e) {
            $this->backendUnavailable('PostgreSQL is not reachable: ' . $e->getMessage());
        }

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // The name was checked above, so it is safe to create it when missing.
        $pdo->exec("CREATE SCHEMA IF NOT EXISTS {$schema}");
        $pdo->exec("SET search_path TO {$schema}");

        $path = trim((string) $pdo->query('SHOW search_path')->fetchColumn(), '" ');

        if ($path !== $schema || $pdo->query('SELECT current_schema()')->fetchColumn() !== $schema) {
            $this->backendUnavailable("Could not pin search_path to [{$schema}] (got [{$path}]).");
        }

        $this->dropTables($pdo);
        QueueSchema::createPgsql($pdo);

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
