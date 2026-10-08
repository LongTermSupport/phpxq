<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support;

use LTS\PhpXq\Cli\FrontController;
use LTS\PhpXq\Cli\FrontControllerInterface;
use RuntimeException;

/**
 * Drives a front controller in-process with in-memory streams, so conformance suites run quickly and
 * need no subprocess per case.
 */
final readonly class CliRunner
{
    public function __construct(
        private FrontControllerInterface $controller = new FrontController(),
    ) {
    }

    /**
     * @param list<string> $args arguments after the program name; the first one is the tool, `jq` or `yq`
     */
    public function run(array $args, string $stdin = ''): CliResult
    {
        $in  = $this->stream($stdin);
        $out = $this->stream('');
        $err = $this->stream('');

        $exitCode = $this->controller->run($in, $out, $err, ...$args);

        return new CliResult($exitCode, $this->contents($out), $this->contents($err));
    }

    /**
     * @return resource
     */
    private function stream(string $contents): mixed
    {
        $stream = fopen('php://memory', 'w+b');
        if (false === $stream) {
            throw new RuntimeException('Could not open an in-memory stream');
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
}
