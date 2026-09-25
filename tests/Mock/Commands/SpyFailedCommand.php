<?php

namespace Doppar\Queue\Tests\Mock\Commands;

use Doppar\Queue\Commands\QueueFailedCommand;

class SpyFailedCommand extends QueueFailedCommand
{
    use SpiesOnConsole;
}
