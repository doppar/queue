<?php

namespace Doppar\Queue\Tests\Mock\Commands;

use Doppar\Queue\Commands\QueueMonitorCommand;

class SpyMonitorCommand extends QueueMonitorCommand
{
    use SpiesOnConsole;
}
