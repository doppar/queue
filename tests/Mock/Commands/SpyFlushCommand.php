<?php

namespace Doppar\Queue\Tests\Mock\Commands;

use Doppar\Queue\Commands\QueueFlushCommand;

class SpyFlushCommand extends QueueFlushCommand
{
    use SpiesOnConsole;
}
