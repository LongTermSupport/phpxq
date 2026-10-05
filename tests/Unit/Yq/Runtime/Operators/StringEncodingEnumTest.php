<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime\Operators;

use LTS\PhpXq\Yq\Runtime\Operators\StringEncodingEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(StringEncodingEnum::class)]
final class StringEncodingEnumTest extends TestCase
{
    #[DataProvider('names')]
    public function testTheValueIsTheNameWrittenAfterTheAtSign(string $name, ?StringEncodingEnum $expected): void
    {
        self::assertSame($expected, StringEncodingEnum::tryFrom($name));
    }

    /**
     * @return iterable<string, array{string, ?StringEncodingEnum}>
     */
    public static function names(): iterable
    {
        yield 'base64url' => ['base64url', StringEncodingEnum::Base64Url];

        yield 'urid' => ['urid', StringEncodingEnum::Urid];

        yield 'a data format is not a string encoding' => ['json', null];
    }
}
