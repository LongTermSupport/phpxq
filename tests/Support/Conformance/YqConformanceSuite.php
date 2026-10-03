<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Conformance;

use LTS\PhpXq\Tests\Support\CliRunner;
use RuntimeException;
use UnexpectedValueException;

/**
 * The documented examples of upstream yq (see tests/Conformance/Yq/fixtures/NOTICE.md) as conformance
 * cases. A case passes when the CLI exits 0 and writes exactly the documented stdout.
 */
final readonly class YqConformanceSuite implements ConformanceSuiteInterface
{
    private string $casesFile;

    /**
     * @param ?string $casesFile the cases.json fixture; defaults to tests/Conformance/Yq/fixtures/cases.json
     */
    public function __construct(?string $casesFile = null)
    {
        $this->casesFile = $casesFile ?? \dirname(__DIR__, 2) . '/Conformance/Yq/fixtures/cases.json';
    }

    public function name(): string
    {
        return 'yq';
    }

    public function cases(): iterable
    {
        $json = is_file($this->casesFile) ? file_get_contents($this->casesFile) : false;
        if (false === $json) {
            throw new RuntimeException('Could not read the yq cases fixture ' . $this->casesFile);
        }

        $rows = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($rows)) {
            throw new UnexpectedValueException('cases.json must hold a list of cases');
        }

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                throw new UnexpectedValueException('Every case must be an object');
            }

            $name       = $row['name']       ?? null;
            $command    = $row['command']    ?? null;
            $flags      = $row['flags']      ?? null;
            $expression = $row['expression'] ?? null;
            $input      = $row['input']      ?? null;
            $expected   = $row['expected']   ?? null;

            if (!\is_string($name) || !\is_string($input) || !\is_string($expected) || !\is_array($flags)) {
                throw new UnexpectedValueException('Malformed case in cases.json');
            }

            $args = ['yq'];
            if (\is_string($command)) {
                $args[] = $command;
            }

            foreach ($flags as $flag) {
                if (!\is_string($flag)) {
                    throw new UnexpectedValueException('Flags must be strings in ' . $name);
                }

                $args[] = $flag;
            }

            if (\is_string($expression)) {
                $args[] = $expression;
            }

            yield new ConformanceCase(
                $name,
                static function (CliRunner $runner) use ($args, $input, $expected): ?string {
                    $result = $runner->run($args, $input);

                    if (0 !== $result->exitCode) {
                        return \sprintf('expected exit code 0, got exit code %d; stderr: %s', $result->exitCode, $result->stderr);
                    }

                    if ($expected !== $result->stdout) {
                        return \sprintf('stdout mismatch: expected %s, got %s', json_encode($expected), json_encode($result->stdout));
                    }

                    return null;
                },
            );
        }
    }
}
