<?php

namespace Doppar\Queue\Tests\Mock\Commands;

use Doppar\Queue\Commands\QueueRetryCommand;

class SpyRetryCommand extends QueueRetryCommand
{
    use SpiesOnConsole;
}
