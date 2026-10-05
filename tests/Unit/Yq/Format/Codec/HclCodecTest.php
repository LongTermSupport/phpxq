<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Emitter\YamlEmitter;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeStyleEnum;
use LTS\PhpXq\Yaml\Parser\YamlParser;
use LTS\PhpXq\Yq\Format\Codec\HclDecoder;
use LTS\PhpXq\Yq\Format\Codec\HclEncoder;
use LTS\PhpXq\Yq\Format\Codec\HclScanner;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class HclCodecTest extends TestCase
{
    public function testDecodesBlocksAttributesAndExpressions(): void
    {
        $hcl = <<<'HCL'
            # head
            locals {
              tags = {
                a = "x"
                "b-c" = 2
              }
              list = [1, "two", var.three]
              expr = var.a + 1
              ok = true
              nothing = null
              ratio = 1.5
              heredoc = <<EOT
            hello
            EOT
            }
            resource "a" "b" {
              ami = "x" # trailing
            }
            resource "a" "c" {
            }
            ingress { port = 1 }
            ingress { port = 2 }

            HCL;

        $expected = <<<'YAML'
            # head
            locals:
              tags:
                a: "x"
                b-c: 2
              list:
                - 1
                - "two"
                - var.three
              expr: var.a + 1
              ok: true
              nothing: null
              ratio: 1.5
              heredoc: |-
                <<EOT
                hello
                EOT
            resource:
              a:
                b:
                  ami: "x" # trailing
                c: {}
            ingress:
              - port: 1
              - port: 2

            YAML;

        self::assertSame($expected, new YamlEmitter()->emit($this->decode($hcl)));
    }

    public function testStringEscapes(): void
    {
        $root = $this->decode('a = "q\" \\\ \n \t \u00e9 \U0001F60A \z ${x}"')->root();

        self::assertSame("q\" \\ \n \t é 😊 \\z \${x}", $root->content[1]->value);
        self::assertSame(NodeStyleEnum::DoubleQuoted, $root->content[1]->style);
    }

    public function testMarkersDistinguishShapes(): void
    {
        $root = $this->decode("a = {b = 1}\nc \"l\" {\n}\nd = 1 + 2\n")->root();

        self::assertTrue($root->content[1]->explicitStart);
        self::assertTrue($root->content[3]->explicitEnd);
        self::assertTrue($root->content[5]->explicitEnd);
    }

    public function testCommentsKeepTheirPlaces(): void
    {
        $document = $this->decode("/* multi\n * line */\na = 1 // tail\n// before b\nb {\n  c = 1\n  # last\n}\n# done\n");
        $root     = $document->root();

        self::assertSame("# multi\n# line", $root->content[0]->headComment);
        self::assertSame('# tail', $root->content[1]->lineComment);
        self::assertSame('# before b', $root->content[2]->headComment);
        self::assertSame('# last', $root->content[3]->content[0]->footComment);
        self::assertSame('# done', $document->footComment);
    }

    #[DataProvider('invalidCases')]
    public function testInvalidInput(string $hcl): void
    {
        $this->expectException(FormatException::class);
        $this->decode($hcl);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCases(): iterable
    {
        yield 'unterminated string' => ['a = "oops'];

        yield 'unterminated block' => ['block {'];

        yield 'stray brace' => ['}'];

        yield 'missing expression' => ["a =\n"];

        yield 'bad block label' => ['block 5 {}'];

        yield 'unterminated comment' => ['/* never ends'];

        yield 'unbalanced brackets' => ['a = [1, 2'];

        yield 'unterminated heredoc' => ["a = <<EOT\nx\n"];

        yield 'bad name' => ['5 = 1'];
    }

    public function testEmptyInputHasNoDocuments(): void
    {
        self::assertSame([], [...new HclDecoder()->decode("\n", new FormatOptions())]);
        self::assertSame(FormatEnum::Hcl, new HclDecoder()->format());
        self::assertSame(FormatEnum::Hcl, new HclEncoder()->format());
    }

    #[DataProvider('roundTrips')]
    public function testRoundTrip(string $hcl, ?string $expected = null): void
    {
        $document = $this->decode($hcl);

        self::assertSame($expected ?? $hcl, new HclEncoder()->encode($document, new FormatOptions(), 0));
    }

    /**
     * @return iterable<string, array{string, 1?: string}>
     */
    public static function roundTrips(): iterable
    {
        yield 'blocks with labels' => [
            "service \"cat\" {\n  process \"main\" {\n    command = [\"/usr/local/bin/awesome-app\", \"server\"]\n  }\n  process \"management\" {\n    command = [\"a\", \"b\"]\n  }\n}\n",
        ];

        yield 'comments' => ["# Configuration\nport = 8080 # server port\n"];

        yield 'expressions are kept raw' => ["sum = 1 + addend\nmessage = \"Hello, \${name}!\"\nshouty = upper(message)\n"];

        yield 'objects and nested lists' => ["tags = {\n  a = \"x\"\n  b = [1, 2]\n  c = {\n    d = 1\n  }\n  e = [{ f = 1 }]\n}\nnames = [\"a\", \"b\"]\n"];

        yield 'repeated blocks' => ["ingress {\n  port = 1\n}\ningress {\n  port = 2\n}\n"];

        yield 'blank lines and odd spacing are normalised' => ["a   =   1\n\n\nb {\n\n  c=2\n}\n", "a = 1\nb {\n  c = 2\n}\n"];

        yield 'empty block' => ["empty \"x\" {\n}\n", "empty \"x\" {\n}\n"];

        yield 'foot comments' => ["b {\n  c = 1\n  # last\n}\n# done\n"];
    }

    public function testEncodesPlainYaml(): void
    {
        $yaml = "a: 1\nb: hello \"q\"\nc: [1, x]\nd:\n  e: true\n  f:\n    g: ~\nh:\n  - i: 1\n  - i: 2\nj: {k: 1}\n\"odd key\": 2.5\n";

        $expected = "a = 1\nb = \"hello \\\"q\\\"\"\nc = [1, \"x\"]\nd {\n  e = true\n  f {\n    g = null\n  }\n}\nh {\n  i = 1\n}\nh {\n  i = 2\n}\nj {\n  k = 1\n}\n\"odd key\" = 2.5\n";

        foreach (new YamlParser()->parse($yaml) as $document) {
            self::assertSame($expected, new HclEncoder()->encode($document, new FormatOptions(), 0));

            return;
        }

        self::fail('no document');
    }

    public function testRejectsNonMapRoots(): void
    {
        $this->expectException(FormatException::class);
        $encoder = new HclEncoder();
        foreach (new YamlParser()->parse("- a\n") as $document) {
            $encoder->encode($document, new FormatOptions(), 0);
        }
    }

    public function testRejectsLabelsHoldingScalars(): void
    {
        $level              = Node::mapping([Node::scalar('l'), Node::scalar('x')]);
        $level->explicitEnd = true;

        $root               = Node::mapping([Node::scalar('block'), $level]);

        $this->expectException(FormatException::class);
        new HclEncoder()->encode($root, new FormatOptions(), 0);
    }

    public function testScalarRootIsPrintedAsIs(): void
    {
        self::assertSame("hello\n", new HclEncoder()->encode(Node::scalar('hello'), new FormatOptions(), 0));
    }

    public function testScannerFindsExpressionEnds(): void
    {
        $text = "f(\"a)\", [1,\n 2]) # tail\nnext";

        self::assertSame(\strlen("f(\"a)\", [1,\n 2]) "), HclScanner::expressionEnd($text, 0));
        self::assertSame(['1', '"a,b"', '[2, 3]', '{ x = 1 }'], HclScanner::splitTop('1, "a,b", [2, 3], { x = 1 }', false));
        self::assertSame(['a = 1', 'b = 2'], HclScanner::splitTop("a = 1\nb = 2", true));
        self::assertTrue(HclScanner::hasComment('a # b'));
        self::assertFalse(HclScanner::hasComment('"a # b"'));
        self::assertSame(\strlen('"x ${ "}" } y"'), HclScanner::skipString('"x ${ "}" } y"', 0));
    }

    private function decode(string $hcl): Node
    {
        foreach (new HclDecoder()->decode($hcl, new FormatOptions()) as $document) {
            return $document;
        }

        self::fail('no document');
    }
}
