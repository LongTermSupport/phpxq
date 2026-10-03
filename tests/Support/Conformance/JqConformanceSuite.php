<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Conformance;

use JsonException;
use LTS\PhpXq\Tests\Support\CliRunner;
use LTS\PhpXq\Tests\Support\Jq\JqTestCase;
use LTS\PhpXq\Tests\Support\Jq\JqTestFileParser;
use SebastianBergmann\Comparator\ComparisonFailure;
use SebastianBergmann\Comparator\Factory;

/**
 * The vendored upstream jq `.test` files (see tests/Conformance/Jq/fixtures/NOTICE.md) as conformance cases.
 *
 * A normal case feeds its input on stdin, runs the program with `-c` and compares the decoded output
 * values with the expected ones. A %%FAIL case must exit with jq's compile error status 3 and write
 * nothing to stdout.
 */
final readonly class JqConformanceSuite implements ConformanceSuiteInterface
{
    public const array FIXTURE_FILES = [
        'jq.test',
        'man.test',
        'onig.test',
        'uri.test',
        'manonig.test',
        'base64.test',
        'optional.test',
    ];

    private const int EXIT_COMPILE_ERROR = 3;

    private const int EXIT_RUNTIME_ERROR = 5;

    private string $directory;

    /**
     * @param ?string      $directory the conformance directory holding `fixtures/` and `modules/`; defaults to tests/Conformance/Jq
     * @param list<string> $files     the `.test` files inside `fixtures/` to load
     */
    public function __construct(
        ?string $directory = null,
        private array $files = self::FIXTURE_FILES,
    ) {
        $this->directory = $directory ?? \dirname(__DIR__, 2) . '/Conformance/Jq';
    }

    public function name(): string
    {
        return 'jq';
    }

    public function cases(): iterable
    {
        $parser = new JqTestFileParser();
        foreach ($this->files as $file) {
            foreach ($parser->parseFile($this->directory . '/fixtures/' . $file) as $case) {
                yield new ConformanceCase(
                    $case->name(),
                    fn (CliRunner $runner): ?string => $this->evaluate($case, $runner),
                );
            }
        }
    }

    private function evaluate(JqTestCase $case, CliRunner $runner): ?string
    {
        $modules = $this->directory . '/modules';

        if ($case->shouldFail) {
            $result = $runner->run(['jq', '-L', $modules, '-n', '-c', '--', $case->program]);

            if (self::EXIT_COMPILE_ERROR !== $result->exitCode) {
                return \sprintf('expected exit code %d, got exit code %d; stderr: %s', self::EXIT_COMPILE_ERROR, $result->exitCode, $result->stderr);
            }

            return '' === $result->stdout ? null : 'expected empty stdout, got: ' . $result->stdout;
        }

        // jq's own test runner exports PAGER=less, which the manual's $ENV.PAGER examples rely on.
        $previousPager = getenv('PAGER');
        putenv('PAGER=less');

        try {
            $result = $runner->run(['jq', '-L', $modules, '-c', '--', $case->program], $case->input . "\n");
        } finally {
            putenv(false === $previousPager ? 'PAGER' : 'PAGER=' . $previousPager);
        }

        // jq's own test runner ignores a runtime error raised after every expected output was produced.
        if (0 !== $result->exitCode && self::EXIT_RUNTIME_ERROR !== $result->exitCode) {
            return \sprintf('expected exit code 0, got exit code %d; stderr: %s', $result->exitCode, $result->stderr);
        }

        try {
            $expected = array_map($this->decode(...), $case->expectedOutputs);
            $actual   = array_map($this->decode(...), $this->outputLines($result->stdout));
        } catch (JsonException $jsonException) {
            return 'Not a JSON text (' . $jsonException->getMessage() . ')';
        }

        try {
            Factory::getInstance()->getComparatorFor($expected, $actual)->assertEquals($expected, $actual);
        } catch (ComparisonFailure) {
            return \sprintf(
                'output mismatch: expected [%s], got [%s]',
                implode(', ', $case->expectedOutputs),
                implode(', ', $this->outputLines($result->stdout)),
            );
        }

        return null;
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

    /**
     * @throws JsonException
     */
    private function decode(string $json): mixed
    {
        return json_decode($json, false, 512, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
    }
}
