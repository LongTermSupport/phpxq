<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime\Operators;

use Generator;
use LTS\PhpXq\Tests\Unit\Yq\Runtime\Support\RecordingFormats;
use LTS\PhpXq\Tests\Unit\Yq\Runtime\Support\YqHarness;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use LTS\PhpXq\Yq\Runtime\Operators\FormatCalls;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `@...` encoders and decoders and `to_X` / `from_X`. A row is an expression and the expected output
 * separated by an arrow; a return sign stands for a newline, a tab sign for a tab and a carriage sign for a
 * carriage return. The input document is always `a: 1`.
 *
 * @internal
 */
#[CoversClass(FormatCalls::class)]
final class FormatCallsTest extends TestCase
{
    private const string ARROW = '➜';

    private const string RETURN = '⏎';

    private const string TAB = '→';

    private const string CARRIAGE = '␍';

    private const string INPUT = "a: 1\n";

    /**
     * @return Generator<string, array{string, string}>
     */
    private static function rows(string $table): Generator
    {
        foreach (explode("\n", trim($table)) as $line) {
            [$expression, $expected] = explode(self::ARROW, $line);

            yield $line => [
                $expression,
                str_replace([self::RETURN, self::TAB, self::CARRIAGE], ["\n", "\t", "\r"], $expected),
            ];
        }
    }

    public function testNamesEveryEncodingAndDataFormatOperator(): void
    {
        $expected = ['@sh', '@uri', '@urid', '@base64', '@base64d', '@base64url', '@base64urld', '@html'];
        foreach (['json', 'yaml', 'props', 'xml', 'toml', 'hcl', 'lua', 'shell', 'kyaml', 'csv', 'tsv'] as $format) {
            $expected[] = '@' . $format;
            $expected[] = '@' . $format . 'd';
            $expected[] = 'to_' . $format;
            $expected[] = 'from_' . $format;
        }

        self::assertSame($expected, new FormatCalls()->names());
    }

    /**
     * @return Generator<string, array{string, string}>
     */
    public static function stringEncodingProvider(): Generator
    {
        yield from self::rows(<<<'TABLE'
            "hello" | @base64➜aGVsbG8=⏎
            "aGVsbG8=" | @base64d➜hello⏎
            "aGVsbG8" | @base64d➜hello⏎
            "aGVsbG8==" | @base64d➜hello⏎
            "???" | @base64url➜Pz8_⏎
            "Pz8_" | @base64urld➜???⏎
            "Pz8/" | @base64d➜???⏎
            "null" | @base64➜bnVsbA==⏎
            null | @base64➜⏎
            .n | @base64➜fg==⏎
            {"a": 1} | @base64➜YTogMQo=⏎
            "a b/~é" | @uri➜a+b%2F~%C3%A9⏎
            "null" | @uri➜null⏎
            1 | @uri➜1⏎
            "a+b%2F%7E" | @urid➜a b/~⏎
            "<a href='x'>&\"" | @html➜&lt;a href=&#39;x&#39;&gt;&amp;&#34;⏎
            "it's" | @sh➜it\'s⏎
            "a b" | @sh➜a' 'b⏎
            "ab cd" | @sh➜ab' 'cd⏎
            "a b'c d" | @sh➜a' 'b\'c' 'd⏎
            "ab" | @sh➜ab⏎
            "a-b_c.d/e:f@g%h+i=j,k" | @sh➜a-b_c.d/e:f@g%h+i=j,k⏎
            "!ab!" | @sh➜'!ab!'⏎
            "'" | @sh➜\'⏎
            "" | @sh➜⏎
            null | @sh➜⏎
            ["a","b"] | @csv➜a,b⏎
            ["a","b"] | @tsv➜a→b⏎
            ["a,b","c\"d", " e", "", null, "\.", "x\ny", "z\rw"] | @csv➜"a,b","c""d"," e",,,"\.","x⏎y","z␍w"⏎
            ["a\tb","c\\d", "x\ny", "z\rw", null] | @tsv➜a\tb→c\\d→x\ny→z\rw→⏎
            ["a;b"] | @csv➜a;b⏎
            ["a\tb"] | @csv➜a→b⏎
            ["a b"] | @tsv➜a b⏎
            [] | @csv➜⏎
            [1, true, 2.5] | @csv➜1,true,2.5⏎
            TABLE);
    }

    #[DataProvider('stringEncodingProvider')]
    public function testEncodesStrings(string $expression, string $expected): void
    {
        self::assertSame($expected, YqHarness::run($expression, "n: ~\n"));
    }

    /**
     * @return Generator<string, array{string, string}>
     */
    public static function recordedProvider(): Generator
    {
        yield from self::rows(<<<'TABLE'
            @json➜json|0|0|0⏎
            @yaml➜yaml|2|1|0⏎⏎
            @xml➜xml|0|1|0⏎⏎
            "a" | @xml➜xml|0|1|0⏎
            @props➜props|2|1|0⏎⏎
            @shell➜shell|2|1|0⏎⏎
            @toml➜toml|2|1|0⏎⏎
            @hcl➜hcl|2|1|0⏎⏎
            @lua➜lua|2|1|0⏎⏎
            @kyaml➜kyaml|2|1|0⏎⏎
            to_json➜json|2|0|0⏎⏎
            to_json(0)➜json|0|0|0⏎
            to_json(4)➜json|4|0|0⏎⏎
            to_xml➜xml|2|1|0⏎⏎
            "a" | to_xml➜xml|2|1|0⏎
            "a" | to_xml(0)➜xml|0|1|0⏎
            to_yaml(3)➜yaml|3|1|0⏎⏎
            to_props(1)➜props|1|1|0⏎⏎
            to_toml➜toml|2|1|0⏎⏎
            "x" | @jsond➜yaml|x⏎
            "x" | from_json➜yaml|x⏎
            "x" | @yamld➜yaml|x⏎
            "x" | from_yaml➜yaml|x⏎
            "x" | @tomld➜toml|x⏎
            "x" | from_toml➜toml|x⏎
            "x" | @xmld➜xml|x⏎
            "x" | from_xml➜xml|x⏎
            "x" | @propsd➜props|x⏎
            "x" | from_props➜props|x⏎
            "x" | @hcld➜hcl|x⏎
            "x" | from_hcl➜hcl|x⏎
            "x" | @luad➜lua|x⏎
            "x" | from_lua➜lua|x⏎
            "x" | @kyamld➜kyaml|x⏎
            "x" | from_kyaml➜kyaml|x⏎
            "x" | @csvd➜csv|x⏎
            "x" | from_csv➜csv|x⏎
            "x" | @tsvd➜tsv|x⏎
            "x" | from_tsv➜tsv|x⏎
            "x\n" | @jsond➜yaml|x⏎⏎
            "" | @yamld➜null⏎
            "x" | @yamld | @yaml➜yaml|2|1|0⏎
            "x\n" | @yamld | @yaml➜yaml|2|1|0⏎⏎
            "x" | @yamld | to_json(4)➜json|4|0|0⏎
            "x" | @yamld | @json➜json|0|0|0⏎
            "x" | @yamld | @xml➜xml|0|1|0⏎
            TABLE);
    }

    #[DataProvider('recordedProvider')]
    public function testHandsTheFormatRegistryItsOptions(string $expression, string $expected): void
    {
        self::assertSame($expected, YqHarness::run($expression, self::INPUT, false, false, null, new RecordingFormats()));
    }

    /**
     * @return Generator<string, array{string, string}>
     */
    public static function failureProvider(): Generator
    {
        yield from self::rows(<<<'TABLE'
            "x" | from_shell➜Unknown format shell
            {} | @yamld➜Cannot decode a map as yaml
            {} | from_json➜Cannot decode a map as json
            "x" | @base64d➜illegal base64 data
            "x" | @base64urld➜illegal base64 data
            .a | @csv➜Cannot encode scalar as csv, it must be an array
            .a | @tsv➜Cannot encode scalar as csv, it must be an array
            {} | @csv➜Cannot encode map as csv, it must be an array
            [[1,[2]]] | @csv➜Cannot encode a collection as a csv cell
            [{}] | @tsv➜Cannot encode a collection as a csv cell
            TABLE);
    }

    #[DataProvider('failureProvider')]
    public function testRejectsWhatCannotBeConverted(string $expression, string $message): void
    {
        try {
            YqHarness::run($expression, self::INPUT, false, false, null, new RecordingFormats());
            self::fail('the conversion does not apply');
        } catch (EvaluationException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }
}
