<?php

namespace Doppar\Queue\Tests\Mock\Commands;

/**
 * Replaces the console plumbing of a command so a test can hand it options
 * and read back what it printed, without a terminal.
 */
trait SpiesOnConsole
{
    /**
     * @var array<string, mixed>
     */
    public array $givenOptions = [];

    /**
     * @var array<int, string>
     */
    public array $lines = [];

    /**
     * @var array<int, string>
     */
    public array $errors = [];

    /**
     * @var array<int, TableSpy>
     */
    public array $tables = [];

    public function withOptions(array $options): static
    {
        $this->givenOptions = $options;

        return $this;
    }

    protected function option($key = null)
    {
        return $key === null ? $this->givenOptions : ($this->givenOptions[$key] ?? null);
    }

    protected function info($string): void
    {
        $this->lines[] = $string;
    }

    protected function error($string): void
    {
        $this->errors[] = $string;
    }

    protected function newLine($count = 1): void
    {
    }

    protected function displaySuccess(string $message): void
    {
        $this->lines[] = $message;
    }

    protected function executeWithTiming(callable $callback): int
    {
        return $callback();
    }

    protected function createTable()
    {
        return $this->tables[] = new TableSpy();
    }
}
