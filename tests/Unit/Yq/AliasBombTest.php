<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq;

use LTS\PhpXq\Tests\Support\CliRunner;
use LTS\PhpXq\Yaml\AliasExpansion;
use LTS\PhpXq\Yq\Format\Codec\NodeTools;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Runtime\Operators\MetaCalls;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

/**
 * A few hundred bytes of nested aliases (`a1: [*a0, *a0, ...]`, level upon level) expand to a document many
 * orders of magnitude larger. Every output that expands aliases, and `explode`, must refuse it with a catchable
 * error instead of running out of time or memory; YAML output, which keeps the aliases, is unaffected.
 *
 * @internal
 */
#[CoversClass(AliasExpansion::class)]
#[CoversClass(NodeTools::class)]
#[CoversClass(MetaCalls::class)]
#[Medium]
final class AliasBombTest extends TestCase
{
    private const string OUTPUT_FORMAT = '--output-format=';

    #[DataProvider('expandingFormats')]
    public function testEveryExpandingEncoderRefusesAnAliasBomb(FormatEnum $format): void
    {
        [$code, $out, $err] = $this->invoke($this->bomb(), self::OUTPUT_FORMAT . $format->value, '.');

        self::assertSame([1, ''], [$code, $out]);
        self::assertStringContainsString(AliasExpansion::ERROR, $err);
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

    /**
     * `explode` rewrites the document in place, and a format operator encodes inside an expression.
     */
    #[DataProvider('expandingExpressions')]
    public function testExpandingOperatorsRefuseAnAliasBomb(string $expression): void
    {
        [$code, $out, $err] = $this->invoke($this->bomb(), $expression);

        self::assertSame([1, ''], [$code, $out]);
        self::assertStringContainsString(AliasExpansion::ERROR, $err);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function expandingExpressions(): iterable
    {
        yield 'explode' => ['explode(.)'];
        yield 'format operator' => ['@json'];
    }

    public function testYamlOutputKeepsTheAliasesAndIsUnaffected(): void
    {
        $bomb = $this->bomb();

        self::assertSame([0, $bomb, ''], $this->invoke($bomb, '.'));
    }

    public function testModestAliasingStillExpands(): void
    {
        self::assertSame([0, "{\"a\":[1,2],\"b\":[1,2]}\n", ''], $this->invoke("a: &a [1, 2]\nb: *a\n", '-o=json', '-I=0', '.'));
    }

    private function bomb(): string
    {
        $yaml = "a0: &a0 [lol]\n";
        for ($level = 1; $level < 5; ++$level) {
            $yaml .= \sprintf("a%d: &a%d [%s]\n", $level, $level, implode(', ', array_fill(0, 10, '*a' . ($level - 1))));
        }

        return $yaml;
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
