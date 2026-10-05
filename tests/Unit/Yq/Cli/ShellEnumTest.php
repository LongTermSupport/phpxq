<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Cli;

use LTS\PhpXq\Yq\Cli\ShellEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ShellEnum::class)]
final class ShellEnumTest extends TestCase
{
    #[DataProvider('names')]
    public function testTheValueIsTheNameOfTheShell(string $name, ?ShellEnum $expected): void
    {
        self::assertSame($expected, ShellEnum::tryFrom($name));
    }

    /**
     * @return iterable<string, array{string, ?ShellEnum}>
     */
    public static function names(): iterable
    {
        yield 'powershell' => ['powershell', ShellEnum::Powershell];

        yield 'an unsupported shell' => ['nushell', null];
    }
}
