<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Emitter\EmitOptions;
use LTS\PhpXq\Yaml\Emitter\YamlEmitterInterface;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\Parser\YamlParserInterface;
use LTS\PhpXq\Yq\Format\Codec\YamlDecoder;
use LTS\PhpXq\Yq\Format\Codec\YamlEncoder;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class YamlCodecTest extends TestCase
{
    public function testDecoderYieldsEveryDocument(): void
    {
        $docs = [...new YamlDecoder()->decode("a: 1\n---\nb: 2\n", new FormatOptions())];

        self::assertCount(2, $docs);
        self::assertSame(FormatEnum::Yaml, new YamlDecoder()->format());
    }

    public function testDecoderReportsSyntaxErrorsAsFormatErrors(): void
    {
        $this->expectException(FormatException::class);
        [...new YamlDecoder()->decode("a: [1, 2\nb: {", new FormatOptions())];
    }

    public function testEncoderPrefixesSeparatorAfterTheFirstResult(): void
    {
        $encoder = new YamlEncoder();
        $node    = Node::mapping([Node::scalar('a'), Node::scalar('1')]);

        self::assertSame("a: 1\n", $encoder->encode($node, new FormatOptions(), 0));
        self::assertSame("---\na: 1\n", $encoder->encode($node, new FormatOptions(), 1));
        self::assertSame("a: 1\n", $encoder->encode($node, new FormatOptions(noDocSeparator: true), 1));
        self::assertSame(FormatEnum::Yaml, $encoder->format());
    }

    public function testEncoderPassesOptionsToTheEmitter(): void
    {
        $emitter = new class implements YamlEmitterInterface {
            public ?EmitOptions $seen = null;

            public function emit(Node $node, EmitOptions $options = new EmitOptions()): string
            {
                $this->seen = $options;

                return "x\n";
            }

            public function emitStream(iterable $nodes, EmitOptions $options = new EmitOptions()): string
            {
                return '';
            }
        };

        new YamlEncoder($emitter)->encode(Node::scalar('x'), new FormatOptions(indent: 4, colors: true, unwrapScalar: false, prettyPrint: true), 0);

        self::assertEquals(new EmitOptions(indent: 4, colors: true, unwrapScalar: false, prettyPrint: true, noDocSeparator: false), $emitter->seen);
    }

    public function testKyamlIsReadAsYaml(): void
    {
        $decoder = new YamlDecoder(format: FormatEnum::Kyaml);
        $docs    = [...$decoder->decode("{\n  a: 1, # one\n  b: [\"x\"],\n}\n", new FormatOptions())];

        self::assertSame(FormatEnum::Kyaml, $decoder->format());
        self::assertCount(1, $docs);
        self::assertSame('a', $docs[0]->root()->content[0]->value);
    }

    public function testDecoderDelegatesToTheGivenParser(): void
    {
        $parser = new class implements YamlParserInterface {
            public function parse(string $yaml): iterable
            {
                yield Node::document(Node::scalar($yaml));
            }
        };

        $docs = [...new YamlDecoder($parser)->decode('hello', new FormatOptions())];

        self::assertSame('hello', $docs[0]->root()->value);
    }
}
