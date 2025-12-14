<?php

namespace Doppar\Queue\Tests\Mock\Class;

class ChainTestState
{
    public bool $callbackCalled = false;

    public function markCalled(): void
    {
        $this->callbackCalled = true;
    }
}