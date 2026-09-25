<?php

namespace Doppar\Queue\Tests\Mock\Commands;

/**
 * Stands in for the console table helper and remembers what was put in it.
 */
class TableSpy
{
    public array $headers = [];

    public array $rows = [];

    public bool $rendered = false;

    public function setHeaders(array $headers): self
    {
        $this->headers = $headers;

        return $this;
    }

    public function addRow(array $row): self
    {
        $this->rows[] = $row;

        return $this;
    }

    public function render(): void
    {
        $this->rendered = true;
    }
}
