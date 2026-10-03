<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Conformance\Yq;

use LTS\PhpXq\Tests\Support\CliRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

/**
 * Runs the documented examples of upstream yq (see fixtures/NOTICE.md) against the phpxq CLI.
 *
 * @internal
 */
final class YqConformanceTest extends TestCase
{
    /**
     * @param list<string> $args
     */
    #[DataProvider('documentedExampleProvider')]
    public function testDocumentedExample(array $args, string $input, string $expected): void
    {
        $result = new CliRunner()->run($args, $input);

        self::assertSame($expected, $result->stdout);
    }

    /**
     * @return iterable<string, array{list<string>, string, string}> args after the program name, stdin, expected stdout
     */
    public static function documentedExampleProvider(): iterable
    {
        $json = file_get_contents(__DIR__ . '/fixtures/cases.json');
        if (false === $json) {
            throw new RuntimeException('Could not read the yq cases fixture');
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

            yield $name => [$args, $input, $expected];
        }
    }
}
