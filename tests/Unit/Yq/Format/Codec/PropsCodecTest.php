<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Emitter\YamlEmitter;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\Parser\YamlParser;
use LTS\PhpXq\Yq\Format\Codec\PropsDecoder;
use LTS\PhpXq\Yq\Format\Codec\PropsEncoder;
use LTS\PhpXq\Yq\Format\Format;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class PropsCodecTest extends TestCase
{
    private const string SAMPLE_YAML = <<<'YAML'
        # block comments come through
        person: # neither do comments on maps
            name: Mike Wazowski # comments on values appear
            pets:
            - cat # comments on array values appear
            - nested:
                - list entry
            food: [pizza] # comments on arrays do not
        emptyArray: []
        emptyMap: []

        YAML;

    public function testEncodesTheDocumentedExample(): void
    {
        $expected = <<<'PROPS'
            # block comments come through
            # comments on values appear
            person.name = Mike Wazowski

            # comments on array values appear
            person.pets.0 = cat
            person.pets.1.nested.0 = list entry
            person.food.0 = pizza

            PROPS;

        self::assertSame($expected, self::encodeYaml(self::SAMPLE_YAML));
    }

    public function testEncodesArrayBrackets(): void
    {
        $out = self::encodeYaml("a:\n  - x\n  - y:\n      - z\n", new FormatOptions(propertiesArrayBrackets: true));

        self::assertSame("a[0] = x\na[1].y[0] = z\n", $out);
    }

    public function testEncodesCustomSeparator(): void
    {
        self::assertSame("a.b :@ c\n", self::encodeYaml("a:\n  b: c\n", new FormatOptions(propertiesSeparator: ' :@ ')));
    }

    public function testEncodesEmptyValuesAndKeepsEmptyStringProperties(): void
    {
        self::assertSame("a = \nb = \n", self::encodeYaml("a: ''\nb: \n"));
    }

    public function testTopLevelScalarIsPrintedBare(): void
    {
        self::assertSame("hello\n", self::encodeYaml('hello'));
    }

    public function testNoUnwrapQuotesStringsWithSpaces(): void
    {
        $out = self::encodeYaml("a: Mike Wazowski\nb: cat\n", new FormatOptions(unwrapScalar: false));

        self::assertSame("a = \"Mike Wazowski\"\nb = cat\n", $out);
    }

    public function testEscapesKeysAndValues(): void
    {
        $out = self::encodeYaml("\"a b:c=d\": \"x\\ty\\nz\"\n");

        self::assertSame("a\\ b\\:c\\=d = x\\ty\\nz\n", $out);
    }

    public function testEncodesRootSequences(): void
    {
        self::assertSame("0 = a\n1 = b\n", self::encodeYaml("- a\n- b\n"));
        self::assertSame("[0] = a\n", self::encodeYaml("- a\n", new FormatOptions(propertiesArrayBrackets: true)));
    }

    public function testDecodesTheDocumentedExample(): void
    {
        $props = <<<'PROPS'
            # block comments come through
            # comments on values appear
            person.name = Mike Wazowski

            # comments on array values appear
            person.pets.0 = cat
            person.pets.1.nested.0 = list entry
            person.food.0 = pizza

            PROPS;

        $expected = <<<'YAML'
            person:
              # block comments come through
              # comments on values appear
              name: Mike Wazowski
              pets:
                # comments on array values appear
                - cat
                - nested:
                    - list entry
              food:
                - pizza

            YAML;

        self::assertSame($expected, self::decodeToYaml($props));
    }

    public function testDecodedValuesAreStrings(): void
    {
        $doc = self::decode("a.b = 10\nc = true\n");

        self::assertSame('!!str', $doc->root()->content[1]->content[1]->tag);
        self::assertSame('!!str', $doc->root()->content[3]->tag);
    }

    public function testSparseArrayIndexesAreFilledWithNulls(): void
    {
        $things = self::decode('things.2 = mike')->root()->content[1];

        self::assertCount(3, $things->content);
        self::assertSame('!!null', $things->content[0]->tag);
        self::assertSame('mike', $things->content[2]->value);
    }

    #[DataProvider('syntaxCases')]
    public function testPropertySyntax(string $input, string $expectedKey, string $expectedValue): void
    {
        $root = self::decode($input)->root();

        self::assertSame($expectedKey, $root->content[0]->value);
        self::assertSame($expectedValue, $root->content[1]->value);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function syntaxCases(): iterable
    {
        yield 'equals' => ["k=v\n", 'k', 'v'];

        yield 'colon' => ["k: v\n", 'k', 'v'];

        yield 'space' => ["k   v\n", 'k', 'v'];

        yield 'equals with spaces' => ["k  =  v w\n", 'k', 'v w'];

        yield 'bang comment is skipped' => ["! nope\nk=v\n", 'k', 'v'];

        yield 'continuation' => ["k=a \\\n    b\n", 'k', 'a b'];

        yield 'escaped backslash at end is not a continuation' => ["k=a\\\\\nj=b\n", 'k', 'a\\'];

        yield 'unicode escape' => ["k=\\u00e9\\n\\t\n", 'k', "é\n\t"];

        yield 'escaped separator in key' => ["a\\ b\\=c=d\n", 'a b=c', 'd'];

        yield 'empty value' => ["k=\n", 'k', ''];

        yield 'key only' => ["k\n", 'k', ''];

        yield 'crlf' => ["k=v\r\n", 'k', 'v'];
    }

    public function testInvalidUnicodeEscape(): void
    {
        $this->expectException(FormatException::class);
        self::decode('k=\\u12');
    }

    public function testFormats(): void
    {
        self::assertSame(Format::Props, new PropsDecoder()->format());
        self::assertSame(Format::Props, new PropsEncoder()->format());
    }

    public function testEmptyInputDecodesToNoDocuments(): void
    {
        self::assertSame([], [...new PropsDecoder()->decode('', new FormatOptions())]);
    }

    private static function decode(string $props): Node
    {
        foreach (new PropsDecoder()->decode($props, new FormatOptions()) as $document) {
            return $document;
        }

        self::fail('no document');
    }

    private static function decodeToYaml(string $props): string
    {
        return new YamlEmitter()->emit(self::decode($props));
    }

    private static function encodeYaml(string $yaml, ?FormatOptions $options = null): string
    {
        foreach (new YamlParser()->parse($yaml) as $document) {
            return new PropsEncoder()->encode($document, $options ?? new FormatOptions(), 0);
        }

        self::fail('no document');
    }
}
