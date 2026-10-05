<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Cli;

use LTS\PhpXq\Yq\Cli\FrontMatterModeEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(FrontMatterModeEnum::class)]
final class FrontMatterModeEnumTest extends TestCase
{
    #[DataProvider('arguments')]
    public function testTheValueIsTheFlagArgument(string $argument, ?FrontMatterModeEnum $expected): void
    {
        self::assertSame($expected, FrontMatterModeEnum::tryFrom($argument));
    }

    /**
     * @return iterable<string, array{string, ?FrontMatterModeEnum}>
     */
    public static function arguments(): iterable
    {
        yield 'extract' => ['extract', FrontMatterModeEnum::Extract];

        yield 'process' => ['process', FrontMatterModeEnum::Process];

        yield 'empty' => ['', null];
    }
}
