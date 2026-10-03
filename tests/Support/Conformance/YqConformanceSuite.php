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

            $stringFlags = [];
            foreach ($flags as $flag) {
                if (!\is_string($flag)) {
                    throw new UnexpectedValueException('Flags must be strings in ' . $name);
                }

                $stringFlags[] = $flag;
            }

            $source = $row['source'] ?? null;
            foreach (self::fileExtensionFlags(\is_string($source) ? $source : '', $stringFlags) as $flag) {
                $args[] = $flag;
            }

            foreach ($stringFlags as $flag) {
                $args[] = $flag;
            }

            // Upstream runs the "FIXED:" scenarios with yamlFixMergeAnchorToSpec enabled.
            if (str_contains($name, ': FIXED:')) {
                $args[] = '--yaml-fix-merge-anchor-to-spec';
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

    /**
     * Upstream's documentation examples name a file (sample.toml) and rely on its extension to pick the
     * input format, and to pick the output format too unless one is given. The cases carry stdin only,
     * so the equivalent explicit flags are supplied for the format-specific usage pages.
     *
     * @param list<string> $flags
     *
     * @return list<string>
     */
    private static function fileExtensionFlags(string $source, array $flags): array
    {
        $format = match ($source) {
            'usage/toml.md' => 'toml',
            'usage/xml.md'  => 'xml',
            'usage/hcl.md'  => 'hcl',
            'usage/lua.md'  => 'lua',
            default         => null,
        };
        if (null === $format) {
            return [];
        }

        $yamlOutput = false;
        foreach ($flags as $flag) {
            if (str_starts_with($flag, '-p') || str_starts_with($flag, '--input-format')) {
                return [];
            }

            if (\in_array($flag, ['-oy', '-o=yaml', '-o=y'], true)) {
                $yamlOutput = true;

                continue;
            }

            if (str_starts_with($flag, '-o') || str_starts_with($flag, '--output-format')) {
                return [];
            }
        }

        return $yamlOutput ? ['-p', $format] : ['-p', $format, '-o', $format];
    }
}
