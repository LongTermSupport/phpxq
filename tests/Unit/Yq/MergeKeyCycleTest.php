<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq;

use LTS\PhpXq\Tests\Support\CliRunner;
use LTS\PhpXq\Yq\Format\Codec\NodeTools;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Runtime\Anchors;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

/**
 * A merge key that merges the mapping it sits in (`a: &a {x: 1, <<: *a}`) is a cycle. Every encoder, `explode`
 * and navigation must treat the re-entered mapping as already merged instead of recursing until the native
 * stack overflows and the process segfaults.
 *
 * @internal
 */
#[CoversClass(NodeTools::class)]
#[CoversClass(Anchors::class)]
#[Medium]
final class MergeKeyCycleTest extends TestCase
{
    private const string CYCLIC = "a: &a {x: 1, <<: *a}\n";

    private const string EQUIVALENT = "a: &a {x: 1}\n";

    private const string OUTPUT_FORMAT = '--output-format=';

    private const string FIXED_MERGE = '--yaml-fix-merge-anchor-to-spec';

    private const string EXPLODE = 'explode(.)';

    #[DataProvider('expandingFormats')]
    public function testEveryEncoderTreatsASelfMergeAsAlreadyMerged(FormatEnum $format): void
    {
        $expected = $this->yq(self::EQUIVALENT, self::OUTPUT_FORMAT . $format->value, '.');

        self::assertSame([0, $expected, ''], $this->invoke(self::CYCLIC, self::OUTPUT_FORMAT . $format->value, '.'));
    }

    /**
     * @return iterable<string, array{FormatEnum}>
     */
    public static function expandingFormats(): iterable
    {
        foreach ([FormatEnum::Json, FormatEnum::Props, FormatEnum::Toml, FormatEnum::Lua, FormatEnum::Shell, FormatEnum::Hcl, FormatEnum::Xml, FormatEnum::Kyaml] as $format) {
            yield $format->value => [$format];
        }
    }

    #[DataProvider('mergeOrders')]
    public function testExplodeOfASelfMergeKeepsTheOwnKeys(bool $fixedMerge): void
    {
        $arguments = $fixedMerge ? [self::FIXED_MERGE, self::EXPLODE] : [self::EXPLODE];

        self::assertSame([0, "a: {x: 1}\n", ''], $this->invoke(self::CYCLIC, ...$arguments));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function mergeOrders(): iterable
    {
        yield 'legacy' => [false];
        yield 'spec-fixed' => [true];
    }

    public function testNavigationThroughASelfMergeFindsTheOwnKey(): void
    {
        self::assertSame([0, "1\n", ''], $this->invoke(self::CYCLIC, '.a.x'));
        self::assertSame([0, "1\n", ''], $this->invoke(self::CYCLIC, '.a[]'));
    }

    private function yq(string $stdin, string ...$arguments): string
    {
        [$code, $out, $err] = $this->invoke($stdin, ...$arguments);
        self::assertSame([0, ''], [$code, $err]);

        return $out;
    }

    /**
     * @return array{int, string, string}
     */
    private function invoke(string $stdin, string ...$arguments): array
    {
        $result = new CliRunner()->run(['yq', ...array_values($arguments)], $stdin);

        return [$result->exitCode, $result->stdout, $result->stderr];
    }
}
