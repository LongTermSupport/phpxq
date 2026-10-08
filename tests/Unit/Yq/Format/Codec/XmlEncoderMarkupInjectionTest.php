<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Tests\Support\CliRunner;
use LTS\PhpXq\Yq\Format\Codec\XmlEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Element names, attribute names and processing-instruction targets come from mapping keys, and comments and
 * processing-instruction text from the document, so the XML encoder must refuse anything that would end the
 * construct early and inject markup: a name that is not an XML Name, `--` in a comment, `?>` in a processing
 * instruction.
 *
 * @internal
 */
#[CoversClass(XmlEncoder::class)]
#[Small]
final class XmlEncoderMarkupInjectionTest extends TestCase
{
    #[DataProvider('injections')]
    public function testMarkupInjectionIsRefused(string $yaml, string $reason): void
    {
        [$code, $out, $err] = $this->encode($yaml);

        self::assertSame([1, ''], [$code, $out]);
        self::assertStringStartsWith('Error: xml: ', $err);
        self::assertStringContainsString($reason, $err);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function injections(): iterable
    {
        yield 'element name' => ["\"x><evil/><y\": 1\n", 'not a valid XML name'];
        yield 'nested element name' => ["a:\n  \"b c\": 1\n", 'not a valid XML name'];
        yield 'element name starting with a digit' => ["a:\n  1b: 1\n", 'not a valid XML name'];
        yield 'empty element name' => ["\"\": 1\n", 'not a valid XML name'];
        yield 'attribute name' => ["a:\n  \"+@x=\\\"1\\\" onload\": 2\n", 'not a valid XML name'];
        yield 'processing instruction target' => ["\"+p_a b\": c\n", 'not a valid XML name'];
        yield 'comment closing early' => ["# c --> <evil/>\na: 1\n", 'comment cannot contain --'];
        yield 'comment on a value' => ["a: 1 # c -- d\n", 'comment cannot contain --'];
        yield 'processing instruction closing early' => ["\"+p_xml-stylesheet\": 'href=\"a\" ?><evil/>'\n", 'processing instruction cannot contain ?>'];
    }

    #[DataProvider('validNames')]
    public function testValidNamesAreWritten(string $yaml, string $xml): void
    {
        self::assertSame([0, $xml, ''], $this->encode($yaml));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validNames(): iterable
    {
        yield 'punctuation allowed in names' => ["a:\n  x-y.z_w: 1\n  ns:tag: 2\n", "<a>\n  <x-y.z_w>1</x-y.z_w>\n  <ns:tag>2</ns:tag>\n</a>\n"];
        yield 'non-ASCII name' => ["ñame: 1\n", "<ñame>1</ñame>\n"];
        yield 'attribute' => ["a:\n  +@id: 1\n", "<a id=\"1\"></a>\n"];
        yield 'processing instruction' => ["+p_xml: version=\"1.0\"\na: 1\n", "<?xml version=\"1.0\"?>\n<a>1</a>\n"];
        yield 'comment with single dashes' => ["# a - b\na: 1\n", "<!-- a - b -->\n<a>1</a>\n"];
    }

    /**
     * @return array{int, string, string}
     */
    private function encode(string $yaml): array
    {
        $result = new CliRunner()->run(['yq', '-o=xml', '.'], $yaml);

        return [$result->exitCode, $result->stdout, $result->stderr];
    }
}
