<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format;

use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class FormatRegistryLookupTest extends TestCase
{
    #[DataProvider('everyFormat')]
    public function testEncodersAreBuiltOnceAndReused(FormatEnum $format): void
    {
        $registry = new FormatRegistry();

        self::assertSame($registry->encoder($format), $registry->encoder($format));
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

    public function testDecodersAreBuiltOnceAndReused(): void
    {
        $registry = new FormatRegistry();

        self::assertSame($registry->decoder(FormatEnum::Json), $registry->decoder(FormatEnum::Json));
        self::assertNotSame($registry->decoder(FormatEnum::Json), $registry->decoder(FormatEnum::Yaml));
    }

    public function testShellCannotBeRead(): void
    {
        try {
            new FormatRegistry()->decoder(FormatEnum::Shell);
        } catch (FormatException $formatException) {
            self::assertSame('cannot read shell input; it is an output only format', $formatException->getMessage());

            return;
        }

        self::fail('expected a FormatException');
    }
}
