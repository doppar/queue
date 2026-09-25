<?php

namespace Doppar\Queue\Tests\Mock\Commands;

use Doppar\Queue\Commands\QueueRunCommand;

class SpyRunCommand extends QueueRunCommand
{
    use SpiesOnConsole;
}
