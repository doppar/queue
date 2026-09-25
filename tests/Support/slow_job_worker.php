<?php

/**
 * Child process used by LeaseRenewalTest: runs one slow job through the real
 * worker (timeout child process included) against Redis and reports the outcome.
 *
 * Usage: php slow_job_worker.php <base64 json spec>
 */

require __DIR__ . '/../../vendor/autoload.php';

use Doppar\Queue\QueueManager;
use Doppar\Queue\QueueWorker;
use Doppar\Queue\Tests\Mock\MockContainer;
use Phaseolies\Database\Database;
use Phaseolies\DI\Container;

$spec = json_decode(base64_decode($argv[1]), true, flags: JSON_THROW_ON_ERROR);

$container = new MockContainer();
Container::setInstance($container);
$container->bind('db', fn() => new Database('default'));

$manager = new QueueManager([
    'default' => 'redis',
    'connections' => ['redis' => [
        'driver' => 'redis',
        'connection' => $spec['url'],
        'options' => ['parameters' => ['database' => $spec['db']]],
        'prefix' => $spec['prefix'],
        'lease' => $spec['lease'],
    ]],
]);

$worker = new QueueWorker($manager);
$worker->setLeaseRenewInterval($spec['renew_every']);

echo $worker->runNextJob('default') ? "processed\n" : "nothing\n";
