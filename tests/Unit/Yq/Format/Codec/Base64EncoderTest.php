<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\NodeStyleEnum;
use LTS\PhpXq\Yq\Format\Codec\Base64Encoder;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class Base64EncoderTest extends TestCase
{
    private const string TAIL = ', can only operate on strings. Please first pipe through another encoding operator to convert the value to a string';

    #[DataProvider('rejectedCases')]
    public function testErrorNamesTheTagAndTheFormat(Node $node, FormatEnum $format, string $expectedHead): void
    {
        try {
            new Base64Encoder($format)->encode($node, new FormatOptions(), 0);
        } catch (FormatException $exception) {
            self::assertSame($expectedHead . self::TAIL, $exception->getMessage());

            return;
        }

        self::fail('expected a FormatException');
    }

    /**
     * @return iterable<string, array{Node, FormatEnum, string}>
     */
    public static function rejectedCases(): iterable
    {
        yield 'integer' => [Node::scalar('12'), FormatEnum::Base64, 'cannot encode !!int as base64'];

        yield 'boolean as base64url' => [Node::scalar('true'), FormatEnum::Base64Url, 'cannot encode !!bool as base64url'];

        yield 'null' => [Node::scalar('~'), FormatEnum::Base64, 'cannot encode !!null as base64'];

        yield 'mapping' => [Node::mapping(), FormatEnum::Base64Url, 'cannot encode !!map as base64url'];

        yield 'sequence' => [Node::sequence(), FormatEnum::Base64, 'cannot encode !!seq as base64'];

        yield 'untagged scalar names its kind' => [new Node(NodeKindEnum::Scalar, '', NodeStyleEnum::Default, 'x'), FormatEnum::Base64, 'cannot encode Scalar as base64'];

        yield 'alias to a number' => [Node::alias('a', Node::scalar('1.5')), FormatEnum::Base64, 'cannot encode !!float as base64'];
    }

    public function testStringsEncodeWithATrailingNewline(): void
    {
        self::assertSame("aGk=\n", new Base64Encoder()->encode(Node::scalar('hi'), new FormatOptions(), 0));
        self::assertSame("aGk=\n", new Base64Encoder(FormatEnum::Base64)->encode(Node::alias('a', Node::scalar('hi')), new FormatOptions(), 0));
    }

    public function testUrlVariantUsesTheUrlAlphabet(): void
    {
        $node = Node::scalar("\xfb\xff");

        self::assertSame("-_8=\n", new Base64Encoder(FormatEnum::Base64Url)->encode($node, new FormatOptions(), 0));
        self::assertSame("+/8=\n", new Base64Encoder(FormatEnum::Base64)->encode($node, new FormatOptions(), 0));
    }

    public function testFormatIsTheConfiguredOne(): void
    {
        self::assertSame(FormatEnum::Base64, new Base64Encoder()->format());
        self::assertSame(FormatEnum::Base64Url, new Base64Encoder(FormatEnum::Base64Url)->format());
    }
}
