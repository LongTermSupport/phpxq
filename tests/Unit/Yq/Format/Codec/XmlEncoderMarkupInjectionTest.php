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
 * Element names, attribute names and processing-instruction targets come from mapping keys, and comments,
 * directives and processing-instruction text from the document, so the XML encoder must refuse anything that
 * would end the construct early and inject markup: a name holding markup characters, a processing-instruction
 * target that is not an XML Name, `-->` in a comment, `?>` in a processing instruction, an unbalanced `<` or
 * `>` in a directive. Names that only break well-formedness, as the reference writes them, are kept.
 *
 * @internal
 */
#[CoversClass(XmlEncoder::class)]
#[Small]
final class XmlEncoderMarkupInjectionTest extends TestCase
{
    private const string BAD_NAME = 'not a valid XML name';

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
        yield 'element name' => ["\"x><evil/><y\": 1\n", self::BAD_NAME];
        yield 'nested element name' => ["a:\n  \"b/c\": 1\n", self::BAD_NAME];
        yield 'element name with an entity' => ["\"a&b\": 1\n", self::BAD_NAME];
        yield 'element name with an equals sign' => ["a:\n  \"b=c\": 1\n", self::BAD_NAME];
        yield 'element name with a quote' => ["\"a'b\": 1\n", self::BAD_NAME];
        yield 'element name opening a declaration' => ["\"a!b\": 1\n", self::BAD_NAME];
        yield 'element name opening an instruction' => ["\"a?b\": 1\n", self::BAD_NAME];
        yield 'empty element name' => ["\"\": 1\n", self::BAD_NAME];
        yield 'attribute name' => ["a:\n  \"+@x=\\\"1\\\" onload\": 2\n", self::BAD_NAME];
        yield 'processing instruction target' => ["\"+p_a b\": c\n", self::BAD_NAME];
        yield 'processing instruction target starting with a digit' => ["+p_1a: c\n", self::BAD_NAME];
        yield 'comment closing early' => ["# c --> <evil/>\na: 1\n", 'comment cannot contain -->'];
        yield 'comment on a value closing early' => ["a: 1 # c --> d\n", 'comment cannot contain -->'];
        yield 'processing instruction closing early' => ["\"+p_xml-stylesheet\": 'href=\"a\" ?><evil/>'\n", 'processing instruction cannot contain ?>'];
        yield 'directive closing early' => ["+directive: \"DOCTYPE x><evil/><y\"\n", 'Directive containing wrong < or > markers'];
        yield 'directive left open' => ["+directive: \"DOCTYPE x <\"\na: 1\n", 'Directive containing wrong < or > markers'];
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
        yield 'comment with a double dash' => ["a: 1 # c -- d\n", "<a>1<!-- c -- d --></a>\n"];
        yield 'comment ending in a dash' => ["# a -\na: 1\n", "<!-- a - -->\n<a>1</a>\n"];
        yield 'name starting with a digit' => ["a:\n  200: 1\n", "<a>\n  <200>1</200>\n</a>\n"];
        yield 'name with a space' => ["my key: 1\n", "<my key>1</my key>\n"];
        yield 'attribute name with a space' => ["a:\n  +@my attr: x\n", "<a my attr=\"x\"></a>\n"];
        yield 'directive with markup in brackets' => ["+directive: \"DOCTYPE x [<!ENTITY y 'z'>]\"\na: 1\n", "<!DOCTYPE x [<!ENTITY y 'z'>]>\n<a>1</a>\n"];
        yield 'directive with a quoted marker' => ["+directive: \"DOCTYPE x '>'\"\na: 1\n", "<!DOCTYPE x '>'>\n<a>1</a>\n"];
        yield 'directive with a comment' => ["+directive: \"DOCTYPE x <!-- > -->\"\na: 1\n", "<!DOCTYPE x <!-- > -->>\n<a>1</a>\n"];
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
