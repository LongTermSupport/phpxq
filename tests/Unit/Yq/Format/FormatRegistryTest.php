<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format;

use LTS\PhpXq\Yq\Format\Codec\LuaDecoder;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class FormatRegistryTest extends TestCase
{
    #[DataProvider('everyFormat')]
    public function testEveryFormatHasAnEncoder(FormatEnum $format): void
    {
        $registry = new FormatRegistry();

        self::assertSame($registry->encoder($format), $registry->encoder($format));
    }

    #[DataProvider('everyFormat')]
    public function testReadableFormatsHaveADecoder(FormatEnum $format): void
    {
        $registry = new FormatRegistry();
        self::assertSame(FormatEnum::Shell !== $format, $format->canDecode());
        if (FormatEnum::Shell === $format) {
            $this->expectException(FormatException::class);
        }

        self::assertSame($registry->decoder($format), $registry->decoder($format));
    }

    /**
     * @return iterable<string, array{FormatEnum}>
     */
    public static function everyFormat(): iterable
    {
        foreach (FormatEnum::cases() as $format) {
            yield $format->value => [$format];
        }
    }

    public function testLuaIsReadable(): void
    {
        self::assertTrue(FormatEnum::Lua->canDecode());
        self::assertInstanceOf(LuaDecoder::class, new FormatRegistry()->decoder(FormatEnum::Lua));
    }
}
