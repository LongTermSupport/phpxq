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

    private const string JSON = '-o=json';

    private const string COMPACT = '-I=0';

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
        self::assertSame([0, "{\"a\":[1,2],\"b\":[1,2]}\n", ''], $this->invoke("a: &a [1, 2]\nb: *a\n", self::JSON, self::COMPACT, '.'));
    }

    /**
     * An 87 KB template, 3000 list items each `{<<: *base, name: nN}` over a 100-key base, writes 5 MB of JSON;
     * the reference prints it, and so must this.
     */
    public function testATemplateMergingASharedBaseIsWrittenInFull(): void
    {
        $yaml = "base: &base\n";
        for ($key = 0; $key < 100; ++$key) {
            $yaml .= \sprintf("  key%d: value%d\n", $key, $key);
        }

        $yaml .= "items:\n";
        for ($item = 0; $item < 3000; ++$item) {
            $yaml .= \sprintf("  - {<<: *base, name: n%d}\n", $item);
        }

        [$code, $out, $err] = $this->invoke($yaml, '(.items | length), .items[2999].name, .items[2999].key99');
        self::assertSame([0, "3000\nn2999\nvalue99\n", ''], [$code, $out, $err]);

        [$code, $out, $err] = $this->invoke($yaml, self::JSON, self::COMPACT, '.');
        self::assertSame([0, ''], [$code, $err]);
        self::assertStringEndsWith(',"key98":"value98","key99":"value99","name":"n2999"}]}' . "\n", $out);
        self::assertGreaterThan(5_000_000, \strlen($out));
    }

    /**
     * A 300-link chain of anchors, each merging the one before, is no bomb: it writes out what the reference writes.
     */
    #[DataProvider('chainReaders')]
    public function testALongMergeChainIsWrittenInFull(string ...$arguments): void
    {
        $yaml = "a0: &a0 {k0: 0}\n";
        for ($i = 1; $i < 300; ++$i) {
            $yaml .= \sprintf("a%d: &a%d {<<: *a%d, k%d: %d}\n", $i, $i, $i - 1, $i, $i);
        }

        [$code, $out, $err] = $this->invoke($yaml, ...$arguments);

        self::assertSame([0, ''], [$code, $err]);
        self::assertSame(300, substr_count($out, '"k'));
        self::assertStringContainsString('"k0":0', $out);
        self::assertStringContainsString('"k299":299', $out);
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function chainReaders(): iterable
    {
        yield 'json' => [self::JSON, self::COMPACT, '.a299'];

        yield 'spec-fixed json' => ['--yaml-fix-merge-anchor-to-spec', self::JSON, self::COMPACT, '.a299'];

        yield 'explode' => [self::JSON, self::COMPACT, 'explode(.) | .a299'];

        yield 'spec-fixed explode' => ['--yaml-fix-merge-anchor-to-spec', self::JSON, self::COMPACT, 'explode(.) | .a299'];
    }

    private function bomb(): string
    {
        $yaml = "a0: &a0 [lol]\n";
        for ($level = 1; $level < 7; ++$level) {
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
