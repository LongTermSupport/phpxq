<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Conformance\Jq;

use JsonException;
use LTS\PhpXq\Tests\Support\CliRunner;
use LTS\PhpXq\Tests\Support\Jq\JqTestCase;
use LTS\PhpXq\Tests\Support\Jq\JqTestFileParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs every case of the vendored upstream jq test suite (see fixtures/NOTICE.md) through the CLI.
 *
 * A normal case feeds its input on stdin, runs the program with `-c` and compares the decoded output
 * values with the expected ones. A %%FAIL case must exit with jq's compile error status 3 and write
 * nothing to stdout.
 *
 * @internal
 */
final class JqConformanceTest extends TestCase
{
    private const int EXIT_COMPILE_ERROR = 3;

    private const array FIXTURE_FILES = [
        'jq.test',
        'man.test',
        'onig.test',
        'uri.test',
        'manonig.test',
        'base64.test',
        'optional.test',
    ];

    #[DataProvider('provideCases')]
    public function testUpstreamCase(JqTestCase $case): void
    {
        $modules = __DIR__ . '/modules';
        $runner  = new CliRunner();

        if ($case->shouldFail) {
            $result = $runner->run(['jq', '-L', $modules, '-n', '-c', '--', $case->program]);

            self::assertSame(self::EXIT_COMPILE_ERROR, $result->exitCode, 'stderr: ' . $result->stderr);
            self::assertSame('', $result->stdout);

            return;
        }

        $result = $runner->run(['jq', '-L', $modules, '-c', '--', $case->program], $case->input . "\n");

        self::assertSame(0, $result->exitCode, 'stderr: ' . $result->stderr);
        self::assertEquals(
            array_map($this->decode(...), $case->expectedOutputs),
            array_map($this->decode(...), $this->outputLines($result->stdout)),
        );
    }

    /**
     * @return iterable<string, array{JqTestCase}>
     */
    public static function provideCases(): iterable
    {
        $parser = new JqTestFileParser();
        foreach (self::FIXTURE_FILES as $file) {
            foreach ($parser->parseFile(__DIR__ . '/fixtures/' . $file) as $case) {
                yield $case->name() => [$case];
            }
        }
    }

    /**
     * @return list<string>
     */
    private function outputLines(string $stdout): array
    {
        if ('' === $stdout) {
            return [];
        }

        return explode("\n", rtrim($stdout, "\n"));
    }

    private function decode(string $json): mixed
    {
        try {
            return json_decode($json, false, 512, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (JsonException $jsonException) {
            self::fail('Not a JSON text (' . $jsonException->getMessage() . '): ' . $json);
        }
    }
}
