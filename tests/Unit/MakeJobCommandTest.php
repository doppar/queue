<?php

declare(strict_types=1);

namespace Doppar\Queue\Tests\Unit;

use Doppar\Queue\Commands\MakeJobCommand;
use PHPUnit\Framework\TestCase;

class MakeJobCommandTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir() . '/doppar-queue-command-' . bin2hex(random_bytes(5));
        mkdir($this->tempRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempRoot);

        parent::tearDown();
    }

    public function testMakeJobCommandSupportsBackslashesAndRelativeOutput(): void
    {
        $command = new class($this->tempRoot) extends MakeJobCommand
        {
            public array $capturedLines = [];
            public array $capturedSuccesses = [];

            public function __construct(private string $tempRoot)
            {
                parent::__construct();
            }

            protected function argument($key = null)
            {
                return $key === 'name' ? 'Reports\\GenerateDaily' : null;
            }

            protected function line(string $string, ?string $style = null): void
            {
                $this->capturedLines[] = $string;
            }

            protected function newLine($count = 1): void
            {
            }

            protected function displaySuccess(string $message): void
            {
                $this->capturedSuccesses[] = $message;
            }

            protected function executeWithTiming(callable $callback): int
            {
                $result = $callback();

                return is_int($result) ? $result : 0;
            }

            protected function generatedFilePath(string $baseDirectory, string $name, string $extension = '.php'): string
            {
                $normalizedName = $this->normalizeGeneratedName($name);
                $relativePath = trim($baseDirectory, '/\\');

                if ($normalizedName !== '') {
                    $relativePath .= '/' . $normalizedName;
                }

                return $this->tempRoot . '/' . $relativePath . $extension;
            }

            protected function relativePath(string $path, ?string $basePath = null): string
            {
                $normalizedPath = str_replace('\\', '/', $path);
                $normalizedBase = rtrim(str_replace('\\', '/', $this->tempRoot), '/');

                return substr($normalizedPath, strlen($normalizedBase) + 1);
            }
        };

        $result = $command->handle();
        $file = $this->tempRoot . '/src/Jobs/Reports/GenerateDailyJob.php';
        $contents = (string) file_get_contents($file);

        $this->assertSame(0, $result);
        $this->assertFileExists($file);
        $this->assertStringContainsString('namespace App\\Jobs\\Reports;', $contents);
        $this->assertStringContainsString('class GenerateDailyJob extends Job', $contents);
        $this->assertContains('Job created successfully', $command->capturedSuccesses);
        $this->assertContains(
            '<fg=yellow>📦 File:</> <fg=white>src/Jobs/Reports/GenerateDailyJob.php</>',
            $command->capturedLines
        );
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
