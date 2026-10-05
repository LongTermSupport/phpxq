<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Cli;

use LTS\PhpXq\Yq\Cli\YqApplication;
use RuntimeException;

/**
 * Runs the yq application in memory against a scratch directory, with the fake evaluator and formats.
 */
final readonly class CliHarness
{
    public FakeEvaluator $evaluator;

    public FakeFormats $formats;

    public string $directory;

    public function __construct()
    {
        $this->evaluator = new FakeEvaluator();
        $this->formats   = new FakeFormats();
        $directory       = sys_get_temp_dir() . '/phpxq-yq-' . bin2hex(random_bytes(6));
        if (!mkdir($directory, 0o755, true)) {
            throw new RuntimeException('could not create ' . $directory);
        }

        $this->directory = $directory;
    }

    public function file(string $name, string $contents): string
    {
        $path = $this->directory . '/' . $name;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o755, true);
        }

        file_put_contents($path, $contents);

        return $path;
    }

    public function read(string $name): string
    {
        return (string)file_get_contents($this->directory . '/' . $name);
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string} exit code, stdout, stderr
     */
    public function run(array $args, string $stdin = ''): array
    {
        $in  = $this->stream($stdin);
        $out = $this->stream('');
        $err = $this->stream('');

        $app  = new YqApplication(evaluator: $this->evaluator, formats: $this->formats);
        $code = $app->run($in, $out, $err, ...$args);

        return [$code, $this->contents($out), $this->contents($err)];
    }

    public function cleanup(): void
    {
        $this->remove($this->directory);
    }

    /**
     * @return resource
     */
    private function stream(string $contents): mixed
    {
        $stream = fopen('php://memory', 'w+b');
        if (false === $stream) {
            throw new RuntimeException('no memory stream');
        }

        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    /**
     * @param resource $stream
     */
    private function contents(mixed $stream): string
    {
        rewind($stream);

        return (string)stream_get_contents($stream);
    }

    private function remove(string $path): void
    {
        if (is_dir($path)) {
            $entries = scandir($path);
            foreach (false === $entries ? [] : $entries as $entry) {
                if ('.' !== $entry && '..' !== $entry) {
                    $this->remove($path . '/' . $entry);
                }
            }

            rmdir($path);

            return;
        }

        unlink($path);
    }
}
