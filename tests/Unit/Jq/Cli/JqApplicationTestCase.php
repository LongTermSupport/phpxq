<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli;

use Closure;
use LTS\PhpXq\Jq\Cli\JqApplication;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;
use LTS\PhpXq\Json\JsonDecoder;
use LTS\PhpXq\Json\JsonEncoder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Runs the jq command line in memory with the real JSON codec, against a fake parser and a fake compiler
 * (the program is a closure, so these tests need no evaluator).
 *
 * @internal
 */
abstract class JqApplicationTestCase extends TestCase
{
    protected JqApplicationFakeCompiler $compiler;

    protected JqApplicationFakeParser $parser;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    /**
     * @param list<string>                                                         $args
     * @param ?Closure(RuntimeContextInterface, mixed, Closure(mixed): void): void $behaviour defaults to the identity filter
     *
     * @return array{int, string, string} exit status, stdout, stderr
     */
    protected function jq(array $args, string $stdin = '', ?Closure $behaviour = null): array
    {
        $this->compiler = new JqApplicationFakeCompiler($behaviour ?? static function (RuntimeContextInterface $context, mixed $input, Closure $emit): void {
            $emit($input);
        });
        $this->parser  = new JqApplicationFakeParser();
        $application   = new JqApplication($this->parser, $this->compiler, new JsonDecoder(), new JsonEncoder());

        $in  = self::memory($stdin);
        $out = self::memory('');
        $err = self::memory('');

        $status = $application->run($in, $out, $err, ...$args);

        return [$status, self::contents($out), self::contents($err)];
    }

    /**
     * @return resource
     */
    protected static function memory(string $contents)
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
    protected static function contents(mixed $stream): string
    {
        rewind($stream);

        return (string)stream_get_contents($stream);
    }

    /**
     * Writes $contents to a fresh file that is removed when the test ends.
     */
    protected function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'jqcli');
        if (false === $path) {
            throw new RuntimeException('no temp file');
        }

        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
