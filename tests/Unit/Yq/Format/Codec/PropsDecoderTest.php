<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yq\Format\Codec\PropsDecoder;
use LTS\PhpXq\Yq\Format\Codec\YamlEncoder;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class PropsDecoderTest extends TestCase
{
    private const string A_ONE = "a: \"1\"\n";

    private const string A_AND_B = "a: \"1\"\nb: \"2\"\n";

    private const string A_TWELVE = "a: \"12\"\n";

    private const string A_ONE_X = "a: 1x\n";

    private const string A_EMPTY = "a: \"\"\n";

    private const string MALFORMED = 'properties: malformed \uxxxx encoding';

    private const string REPLACEMENT = "\u{FFFD}";

    #[DataProvider('shapeCases')]
    public function testShapes(string $properties, string $expectedYaml): void
    {
        $yaml = new YamlEncoder()->encode($this->decode($properties), new FormatOptions(), 0);

        self::assertSame($expectedYaml, $yaml);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function shapeCases(): iterable
    {
        yield 'array from consecutive indexes' => ["a.0=x\na.1=y\n", "a:\n  - x\n  - y\n"];

        yield 'array with a leading gap' => ["a.1=y\n", "a:\n  - null\n  - y\n"];

        yield 'array with a long gap' => ["a.5=x\n", "a:\n  - null\n  - null\n  - null\n  - null\n  - null\n  - x\n"];

        yield 'array of maps' => ["a.0.b=x\na.0.c=y\na.1.b=z\n", "a:\n  - b: x\n    c: y\n  - b: z\n"];

        yield 'nested maps' => ["a.b.c=1\na.b.d=2\n", "a:\n  b:\n    c: \"1\"\n    d: \"2\"\n"];

        yield 'ten digit segment is a key' => ["a.1234567890=x\n", "a:\n  \"1234567890\": x\n"];

        yield 'leading zero segment is an index' => ["a.01=x\n", "a:\n  - null\n  - x\n"];

        yield 'letter and digit segments are keys' => ["a.x1=x\n", "a:\n  x1: x\n"];

        yield 'digit and letter segment is a key' => ["a.1x=x\n", "a:\n  1x: x\n"];

        yield 'map replaces an array' => ["a.0=x\na.b=y\n", "a:\n  b: y\n"];

        yield 'index key inside a map stays a key' => ["a.b=y\na.0=x\n", "a:\n  b: y\n  \"0\": x\n"];

        yield 'map next to a nested map' => ["a.b=y\na.c.d=x\n", "a:\n  b: y\n  c:\n    d: x\n"];

        yield 'scalar replaced by a map' => ["a=1\na.b=2\n", "a:\n  b: \"2\"\n"];

        yield 'array item replaced by a map' => ["a.0=1\na.0.b=2\n", "a:\n  - b: \"2\"\n"];

        yield 'array item map replaced by a scalar' => ["a.0.b=1\na.0=2\n", "a:\n  - \"2\"\n"];

        yield 'array overwrite' => ["a.b.0=1\na.b.1=2\na.b.0=3\n", "a:\n  b:\n    - \"3\"\n    - \"2\"\n"];

        yield 'overwrite' => ["a=1\na=2\n", "a: \"2\"\n"];

        yield 'head comments' => ["# c1\n# c2\na=1\n", "# c1\n# c2\na: \"1\"\n"];

        yield 'bang comment' => ["! bang\na=1\n", "# bang\na: \"1\"\n"];

        yield 'empty comment' => ["#\na=1\n", "#\na: \"1\"\n"];

        yield 'blank comment' => ["#   \na=1\n", "#\na: \"1\"\n"];

        yield 'comment without a space' => ["#x\na=1\n", "# x\na: \"1\"\n"];

        yield 'comment before a blank line' => ["# c\n\na=1\n", "# c\na: \"1\"\n"];

        yield 'comment only on the first property' => ["# c\na=1\nb=2\n", "# c\na: \"1\"\nb: \"2\"\n"];

        yield 'second comment replaces the first on an overwritten key' => ["# c\na=1\n# d\na=2\n", "# d\na: \"2\"\n"];

        yield 'comment on a nested key' => ["# c\na.b=1\n", "a:\n  # c\n  b: \"1\"\n"];

        yield 'comments on two nested keys' => ["# c\na.b.c=1\n# d\na.b.d=2\n", "a:\n  b:\n    # c\n    c: \"1\"\n    # d\n    d: \"2\"\n"];

        yield 'comment on an array item' => ["# c\na.0=1\n", "a:\n  # c\n  - \"1\"\n"];

        yield 'trailing comment is dropped' => ["a=1\n# trailing\n", self::A_ONE];

        yield 'leading blanks of every kind' => ["  a=1\n\t b=2\n\f c=3\n", "a: \"1\"\nb: \"2\"\nc: \"3\"\n"];

        yield 'continuation line' => ["a=1 \\\n  2\n", "a: 1 2\n"];

        yield 'continuation without a space' => ["a=1\\\n2\n", self::A_TWELVE];

        yield 'escaped backslash at the end of a line is not a continuation' => ["a=1\\\\\nb=2\n", "a: 1\\\nb: \"2\"\n"];

        yield 'escaped backslash then a continuation' => ["a=1\\\\\\\n2\n", "a: 1\\2\n"];

        yield 'continuation at the end of the input' => ['a=1\\', self::A_ONE];

        yield 'continuation before the last newline' => ["a=1\\\n", self::A_ONE];

        yield 'two continuations' => ["a=1\\\n\\\n2\n", self::A_TWELVE];

        yield 'continuation after blank space' => ["a=1\\\n   \\\n2\n", self::A_TWELVE];

        yield 'continuation with a space indent' => ["a=1\\\n   x\n", self::A_ONE_X];

        yield 'continuation with a tab indent' => ["a=1\\\n \t x\n", self::A_ONE_X];

        yield 'continuation with a form feed indent' => ["a=1\\\n\f x\n", self::A_ONE_X];

        yield 'continuation inside a key' => ["a\\\n  b=1\n", "ab: \"1\"\n"];

        yield 'space separator' => ["a b\n", "a: b\n"];

        yield 'space separator keeps later spaces' => ["a   b  c\n", "a: b  c\n"];

        yield 'colon separator' => ["a:1\n", self::A_ONE];

        yield 'colon separator with spaces' => ["a : 1\n", self::A_ONE];

        yield 'equals separator with spaces' => ["a = 1\n", self::A_ONE];

        yield 'empty value' => ["a=\n", self::A_EMPTY];

        yield 'key only' => ["a\n", self::A_EMPTY];

        yield 'value keeps trailing spaces' => ["a=  x  \n", "a: 'x  '\n"];

        yield 'tab separators' => ["a\t=\tx\n", "a: x\n"];

        yield 'form feed separators' => ["a\f=\fx\n", "a: x\n"];

        yield 'escaped equals in a key' => ["a\\=b=c\n", "a=b: c\n"];

        yield 'escaped space in a key' => ["a\\ b=c\n", "a b: c\n"];

        yield 'escaped colon in a key' => ["a\\:b:c\n", "a:b: c\n"];

        yield 'equals in a value' => ["a=b=c\n", "a: b=c\n"];

        yield 'colon in a value' => ["a=b:c\n", "a: b:c\n"];

        yield 'equals after a colon separator' => ["a:b=c\n", "a: b=c\n"];

        yield 'escaped equals at the start of a key' => ["\\=a=b\n", "=a: b\n"];

        yield 'escaped backslash at the end of a key' => ["a\\\\=b\n", "a\\: b\n"];

        yield 'carriage return and line feed endings' => ["a=1\r\nb=2\r\n", self::A_AND_B];

        yield 'carriage return endings' => ["a=1\rb=2\r", self::A_AND_B];

        yield 'blank lines' => ["a=1\n\n\nb=2\n", self::A_AND_B];

        yield 'continuation as the whole value' => ["a=\\\n", self::A_EMPTY];

        yield 'empty nested value' => ["a.b=\n", "a:\n  b: \"\"\n"];

        yield 'leading dot' => [".a=1\n", "\"\":\n  a: \"1\"\n"];

        yield 'trailing dot' => ["a.=1\n", "a:\n  \"\": \"1\"\n"];

        yield 'double dot' => ["a..b=1\n", "a:\n  \"\":\n    b: \"1\"\n"];

        yield 'indented second index' => ["a.0=1\n a.1=2\n", "a:\n  - \"1\"\n  - \"2\"\n"];

        yield 'continuation line as its own empty property' => ["a=\n\\\n", "a: \"\"\n\"\": \"\"\n"];

        yield 'no properties' => ["# only\n", "{}\n"];
    }

    #[DataProvider('valueCases')]
    public function testValues(string $properties, string $expectedValue): void
    {
        $root = $this->decode($properties)->root();

        self::assertSame($expectedValue, $root->content[1]->value);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function valueCases(): iterable
    {
        yield 'simple escapes' => ['a=\t\n\r\f\\\\\x' . "\n", "\t\n\r\f\\x"];

        yield 'unicode escapes' => ['a=\u0041\u00e9' . "\n", "A\u{E9}"];

        yield 'surrogate pair' => ['a=\uD83D\uDE00' . "\n", "\u{1F600}"];

        yield 'lone high surrogate' => ['a=\uD83D' . "\n", self::REPLACEMENT];

        yield 'high surrogate then text' => ['a=\uD83Dx' . "\n", self::REPLACEMENT . 'x'];

        yield 'lone low surrogate' => ['a=\uDE00' . "\n", self::REPLACEMENT];

        yield 'high surrogate then a plain escape' => ['a=\uD800\u0041' . "\n", self::REPLACEMENT . 'A'];

        yield 'highest pair' => ['a=\uDBFF\uDFFF' . "\n", "\u{10FFFF}"];

        yield 'highest high surrogate with the lowest low surrogate' => ['a=\uDBFF\uDC00' . "\n", "\u{10FC00}"];

        yield 'lowest pair' => ['a=\uD800\uDC00' . "\n", "\u{10000}"];

        yield 'just below the surrogates' => ['a=\uD7FF' . "\n", "\u{D7FF}"];

        yield 'just above the surrogates' => ['a=\uE000' . "\n", "\u{E000}"];

        yield 'last low surrogate' => ['a=\uDFFF' . "\n", self::REPLACEMENT];

        yield 'first high surrogate' => ['a=\uD800' . "\n", self::REPLACEMENT];

        yield 'last high surrogate' => ['a=\uDBFF' . "\n", self::REPLACEMENT];

        yield 'first low surrogate' => ['a=\uDC00' . "\n", self::REPLACEMENT];

        yield 'two high surrogates' => ['a=\uD800\uDBFF' . "\n", self::REPLACEMENT . self::REPLACEMENT];

        yield 'unicode escape inside text' => ['a=x\u0041y' . "\n", 'xAy'];

        yield 'trailing backslash' => ['a=\\', ''];
    }

    #[DataProvider('malformedCases')]
    public function testMalformedUnicodeEscapes(string $properties): void
    {
        try {
            $this->decode($properties);
        } catch (FormatException $formatException) {
            self::assertSame(self::MALFORMED, $formatException->getMessage());

            return;
        }

        self::fail('expected a FormatException');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedCases(): iterable
    {
        yield 'short escape' => ['a=\u00' . "\n"];

        yield 'non-hex escape' => ['a=\u00zz' . "\n"];

        yield 'short low surrogate escape' => ['a=\uD83D\u00' . "\n"];
    }

    public function testUnicodeEscapeInAKey(): void
    {
        $root = $this->decode('\u0061=1' . "\n")->root();

        self::assertSame('a', $root->content[0]->value);
    }

    public function testAKeyPathNestingBeyondTheLimitIsRefused(): void
    {
        $this->expectException(FormatException::class);
        $this->expectExceptionMessage(Node::depthError());

        $this->decode(str_repeat('a.', Node::MAX_DEPTH) . "a = 1\n");
    }

    public function testBlankInputHasNoDocuments(): void
    {
        self::assertSame([], [...new PropsDecoder()->decode("  \n\t", new FormatOptions())]);
    }

    private function decode(string $properties): Node
    {
        foreach (new PropsDecoder()->decode($properties, new FormatOptions()) as $document) {
            return $document;
        }

        self::fail('no document');
    }
}
