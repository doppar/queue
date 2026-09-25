<?php

namespace Doppar\Queue\Commands\Concerns;

trait ReadsOptions
{
    /**
     * Read a command option as a non-empty string, or null when it was not given.
     *
     * @param string $key
     * @return string|null
     */
    protected function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
