<?php

namespace Doppar\Queue\Tests\Support;

/**
 * For tests that need a real backend (Redis, MySQL, PostgreSQL, processes).
 *
 * By default a missing backend skips the test, so the suite runs anywhere.
 * With QUEUE_TEST_REQUIRE_BACKENDS=1 (set in CI) a missing backend FAILS the
 * test instead, so a service that did not start cannot make the run look green.
 */
trait NeedsBackend
{
    protected function backendUnavailable(string $reason): never
    {
        if (getenv('QUEUE_TEST_REQUIRE_BACKENDS')) {
            $this->fail("A required test backend is unavailable: {$reason}");
        }

        $this->markTestSkipped($reason);
    }
}
