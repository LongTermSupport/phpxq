<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yq\Format\Codec\Base64Decoder;
use LTS\PhpXq\Yq\Format\Codec\Base64Encoder;
use LTS\PhpXq\Yq\Format\Codec\UriDecoder;
use LTS\PhpXq\Yq\Format\Codec\UriEncoder;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class Base64CodecTest extends TestCase
{
    public function testDecodesBase64WithSurroundingWhitespace(): void
    {
        $docs = [...new Base64Decoder(FormatEnum::Base64)->decode("\n YSBzcGVjaWFsIHN0cmluZw==  \n\n", new FormatOptions())];

        self::assertCount(1, $docs);
        self::assertSame('a special string', $docs[0]->root()->value);
        self::assertSame('!!str', $docs[0]->root()->tag);
    }

    public function testDecodesBase64Url(): void
    {
        $docs = [...new Base64Decoder(FormatEnum::Base64Url)->decode('V29ya3Mgd2l0aCBVVEYtMTYg8J-Yig==', new FormatOptions())];

        self::assertSame('Works with UTF-16 😊', $docs[0]->root()->value);
    }

    public function testDecodeRejectsGarbage(): void
    {
        $this->expectException(FormatException::class);
        [...new Base64Decoder(FormatEnum::Base64)->decode('***', new FormatOptions())];
    }

    public function testEncodes(): void
    {
        $node = Node::scalar('a special string');

        self::assertSame("YSBzcGVjaWFsIHN0cmluZw==\n", new Base64Encoder(FormatEnum::Base64)->encode($node, new FormatOptions(), 0));
        self::assertSame("Pj4tPz8_\n", new Base64Encoder(FormatEnum::Base64Url)->encode(Node::scalar('>>-???'), new FormatOptions(), 0));
    }

    public function testEncodeOnlyAcceptsStrings(): void
    {
        $this->expectException(FormatException::class);
        new Base64Encoder(FormatEnum::Base64)->encode(Node::scalar('12'), new FormatOptions(), 0);
    }

    public function testEncodeRejectsCollections(): void
    {
        $this->expectException(FormatException::class);
        new Base64Encoder(FormatEnum::Base64)->encode(Node::sequence(), new FormatOptions(), 0);
    }

    public function testUriRoundTrip(): void
    {
        $encoded = new UriEncoder()->encode(Node::scalar('this has & special () characters *'), new FormatOptions(), 0);
        self::assertSame("this+has+%26+special+%28%29+characters+%2A\n", $encoded);

        $docs = [...new UriDecoder()->decode($encoded, new FormatOptions())];
        self::assertSame('this has & special () characters *', $docs[0]->root()->value);
    }

    public function testUriEncoderRejectsCollections(): void
    {
        $this->expectException(FormatException::class);
        new UriEncoder()->encode(Node::mapping(), new FormatOptions(), 0);
    }
}
