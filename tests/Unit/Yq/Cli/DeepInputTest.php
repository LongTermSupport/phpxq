<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Cli;

use Generator;
use LTS\PhpXq\Tests\Support\Yq\YqProcess;
use LTS\PhpXq\Yq\Format\FormatEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A CLI must never die on a native stack overflow: a very deeply nested input ends with a clean error and
 * exit status 1, at the default 8 MB stack, with and without Xdebug coverage (which uses more stack per call).
 *
 * @internal
 */
final class DeepInputTest extends TestCase
{
    /** Far beyond any limit, so that only a guard can stop the recursion. */
    private const int DEPTH = 200_000;

    /** Deepest block nesting a test builds one level per line, which costs a quadratic amount of indentation. */
    private const int BLOCK_MAPPING_DEPTH = 12_000;

    /** The readers word the limit differently: the TOML and Lua readers say "nesting is too deep". */
    private const string DEPTH_ERROR_PATTERN = '/exceeded max depth|nesting is too deep/';

    /** Writers that must never be reached with a too deep document. */
    private const array ALL_WRITERS = [
        FormatEnum::Yaml,
        FormatEnum::Json,
        FormatEnum::Xml,
        FormatEnum::Props,
        FormatEnum::Toml,
        FormatEnum::Lua,
        FormatEnum::Hcl,
        FormatEnum::Kyaml,
        FormatEnum::Shell,
        FormatEnum::Csv,
    ];

    #[DataProvider('deepInputs')]
    public function testDeepInputFailsCleanlyAtTheDefaultStack(FormatEnum $format, string $text, FormatEnum $output): void
    {
        foreach ([YqProcess::XDEBUG_OFF, YqProcess::XDEBUG_COVERAGE] as $xdebugMode) {
            $result = YqProcess::run(['-p=' . $format->value, '-o=' . $output->value, '.'], $text, $xdebugMode);

            self::assertSame(1, $result->exitCode, $xdebugMode . ': ' . substr($result->stderr, 0, 200));
            self::assertMatchesRegularExpression(self::DEPTH_ERROR_PATTERN, $result->stderr, $xdebugMode);
            self::assertSame('', $result->stdout, $xdebugMode);
        }
    }

    /**
     * @return Generator<string, array{FormatEnum, string, FormatEnum}> input format, input text, output format
     */
    public static function deepInputs(): Generator
    {
        $n          = self::DEPTH;
        $assignment = 'a = ';

        $inputs = [
            'yaml flow sequence'  => [FormatEnum::Yaml, str_repeat('[', $n) . str_repeat(']', $n)],
            'yaml flow mapping'   => [FormatEnum::Yaml, str_repeat('{"a":', $n) . '1' . str_repeat('}', $n)],
            'yaml block sequence' => [FormatEnum::Yaml, str_repeat('- ', $n) . "1\n"],
            'yaml block mapping'  => [FormatEnum::Yaml, self::blockMapping(self::BLOCK_MAPPING_DEPTH)],
            'json arrays'         => [FormatEnum::Json, str_repeat('[', $n) . str_repeat(']', $n)],
            'json objects'        => [FormatEnum::Json, str_repeat('{"a":', $n) . '1' . str_repeat('}', $n)],
            'xml elements'        => [FormatEnum::Xml, str_repeat('<a>', $n) . 'x' . str_repeat('</a>', $n)],
            'toml arrays'         => [FormatEnum::Toml, $assignment . str_repeat('[', $n) . str_repeat(']', $n) . "\n"],
            'toml inline tables'  => [FormatEnum::Toml, $assignment . str_repeat('{a=', $n) . '1' . str_repeat('}', $n) . "\n"],
            'lua tables'          => [FormatEnum::Lua, 'return ' . str_repeat('{', $n) . str_repeat('}', $n) . "\n"],
            'props path'          => [FormatEnum::Props, str_repeat('a.', $n) . "a = 1\n"],
            'hcl arrays'          => [FormatEnum::Hcl, $assignment . str_repeat('[', $n) . str_repeat(']', $n) . "\n"],
            'hcl objects'         => [FormatEnum::Hcl, $assignment . str_repeat('{a=', $n) . '1' . str_repeat('}', $n) . "\n"],
        ];

        foreach ($inputs as $name => [$format, $text]) {
            // The reader refuses the input before any writer runs, so every writer is only exercised for YAML.
            $outputs = FormatEnum::Yaml === $format ? self::ALL_WRITERS : [FormatEnum::Yaml, FormatEnum::Json];
            foreach ($outputs as $output) {
                yield $name . ' to ' . $output->value => [$format, $text, $output];
            }
        }
    }

    private static function blockMapping(int $depth): string
    {
        $lines = [];
        for ($level = 0; $level < $depth; ++$level) {
            $lines[] = str_repeat(' ', $level) . 'a:';
        }

        $lines[] = str_repeat(' ', $depth) . '1';

        return implode("\n", $lines) . "\n";
    }
}
