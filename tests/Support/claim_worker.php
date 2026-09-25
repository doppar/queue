<?php

/**
 * Child process used by ConcurrentClaimTest.
 *
 * Usage: php claim_worker.php <base64 json spec>
 *
 * Waits for the start barrier, then claims jobs until the queue is empty,
 * printing one claimed payload per line. In "crash" mode it claims a single
 * job and kills itself with SIGKILL without acknowledging it.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Doppar\Queue\Drivers\DatabaseDriver;
use Doppar\Queue\Drivers\RedisDriver;
use Phaseolies\Database\Database;
use Predis\Client;

$spec = json_decode(base64_decode($argv[1]), true, flags: JSON_THROW_ON_ERROR);

if ($spec['backend'] === 'pgsql') {
    $pdo = new PDO($spec['pg_dsn'], $spec['pg_user'], $spec['pg_pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('SET search_path TO ' . $spec['pg_schema']);
    (new ReflectionProperty(Database::class, 'connections'))->setValue(null, ['contract' => $pdo]);

    $driver = new DatabaseDriver(['connection' => 'contract', 'lease' => $spec['lease']]);
} elseif ($spec['backend'] === 'mysql') {
    $pdo = new PDO($spec['dsn'], $spec['user'], $spec['pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    (new ReflectionProperty(Database::class, 'connections'))->setValue(null, ['contract' => $pdo]);

    $driver = new DatabaseDriver(['connection' => 'contract', 'lease' => $spec['lease']]);
} elseif ($spec['backend'] === 'sqlite') {
    $pdo = new PDO('sqlite:' . $spec['file']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_TIMEOUT, 30);
    $pdo->exec('PRAGMA busy_timeout = 30000');
    (new ReflectionProperty(Database::class, 'connections'))->setValue(null, ['contract' => $pdo]);

    $driver = new DatabaseDriver(['connection' => 'contract', 'lease' => $spec['lease']]);
} else {
    $driver = new RedisDriver(
        ['prefix' => $spec['prefix'], 'lease' => $spec['lease']],
        new Client($spec['url'], ['parameters' => ['database' => $spec['db']]])
    );
}

// Start barrier: every worker begins at the same instant to maximise contention.
while (microtime(true) < $spec['start_at']) {
    usleep(200);
}

if ($spec['mode'] === 'crash') {
    $job = $driver->pop('default');
    echo $job?->payload, "\n";
    posix_kill(getmypid(), SIGKILL);
}

$idle = 0;

while ($idle < 3) {
    $job = $driver->pop('default');

    if ($job === null) {
        $idle++;
        usleep(20_000);
        continue;
    }

    $idle = 0;
    echo $job->payload, "\n";

    // Hold the job for a moment so other workers overlap with this one.
    usleep(random_int(0, 400));

    $driver->delete($job);
}
