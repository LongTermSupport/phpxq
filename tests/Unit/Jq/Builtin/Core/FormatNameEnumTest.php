<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core;

use LTS\PhpXq\Jq\Builtin\Core\FormatNameEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(FormatNameEnum::class)]
final class FormatNameEnumTest extends TestCase
{
    #[DataProvider('names')]
    public function testTheValueIsTheNameWrittenAfterTheAtSign(string $name, ?FormatNameEnum $expected): void
    {
        self::assertSame($expected, FormatNameEnum::tryFrom($name));
    }

    /**
     * @return iterable<string, array{string, ?FormatNameEnum}>
     */
    public static function names(): iterable
    {
        yield 'base32d' => ['base32d', FormatNameEnum::Base32d];

        yield 'a data format is not a format name' => ['yaml', null];
    }
}
